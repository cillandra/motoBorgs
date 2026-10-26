<?php

namespace App\Services\Pos;

use App\Models\Approval;
use App\Models\Cart;
use App\Models\User;
use App\Models\Variant;
use App\Services\Audit\AuditRecorder;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function owned(User $cashier, Cart $cart): void
    {
        abort_unless($cashier->active && $cart->cashier_id === $cashier->id, 403);
    }

    public function editable(User $cashier, Cart $cart, int $version): void
    {
        $this->owned($cashier, $cart);
        abort_unless($cart->status === 'draft', 409, 'Resume this held cart before changing it.');
        abort_unless($cart->version === $version, 409, 'This cart changed. Reload and review the current order.');
    }

    public function invalidate(Cart $cart): void
    {
        $cart->approvals()->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
        $cart->discount_centavos = 0;
        $cart->version++;
    }

    public function mutate(User $cashier, Cart $cart, int $expectedVersion, array $changes): Cart
    {
        return DB::transaction(function () use ($cashier, $cart, $expectedVersion, $changes): Cart {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->editable($cashier, $cart, $expectedVersion);
            $action = $changes['action'] ?? '';
            abort_unless(in_array($action, ['add', 'set', 'remove', 'refresh_prices'], true), 422, 'Unknown cart action.');
            if ($action === 'refresh_prices') {
                foreach ($cart->lines()->with('variant')->get() as $line) {
                    $line->update(['price_centavos' => $line->variant->price_centavos]);
                }
            } else {
                $variant = Variant::with('product')->findOrFail($changes['variant_id'] ?? 0);
                $line = $cart->lines()->where('variant_id', $variant->id)->first();
                if ($action === 'remove') {
                    abort_unless($line, 404);
                    $line->delete();
                } else {
                    $quantity = $changes['quantity'] ?? null;
                    abort_unless(is_int($quantity) && $quantity > 0 && $quantity <= 1000000, 422, 'Enter a positive whole quantity.');
                    $quantity += $action === 'add' ? ($line?->quantity ?? 0) : 0;
                    abort_if($variant->archived_at || $variant->product->archived_at, 409, 'This variant is unavailable.');
                    abort_if($quantity > $variant->stock, 409, 'Quantity exceeds current available stock.');
                    if ($line) {
                        $line->update(['quantity' => $quantity]);
                    } else {
                        $cart->lines()->create(['variant_id' => $variant->id, 'quantity' => $quantity, 'price_centavos' => $variant->price_centavos]);
                    }
                }
            }
            $this->invalidate($cart);
            $cart->save();
            $this->quote($cashier, $cart);

            return $cart->refresh();
        }, 3);
    }

    /** @return array{version:int,lines:array,subtotal:int,discount:int,total:int,warnings:array} */
    public function quote(User $cashier, Cart $cart, bool $includeApprovalWarnings = true): array
    {
        $cart = $cart->fresh();
        $this->owned($cashier, $cart);
        $lines = [];
        $warnings = [];
        $subtotal = 0;
        foreach ($cart->lines()->with(['variant.product', 'variant.unit'])->orderBy('id')->get() as $line) {
            $variant = $line->variant;
            $gross = $variant->price_centavos * $line->quantity;
            abort_if($gross > Money::MAX_CENTAVOS - $subtotal, 422, 'Order exceeds the transaction amount limit.');
            $subtotal += $gross;
            if ($variant->archived_at || $variant->product->archived_at) {
                $warnings[] = $variant->sku.': this variant is no longer available.';
            }
            if ($variant->stock < $line->quantity) {
                $warnings[] = $variant->sku.': stock changed. Reduce the quantity.';
            }
            if ($variant->price_centavos !== $line->price_centavos) {
                $warnings[] = $variant->sku.': price changed. Review and acknowledge current prices.';
            }
            $lines[] = ['id' => $line->id, 'variant_id' => $variant->id, 'sku' => $variant->sku, 'name' => $variant->product->name,
                'variant' => $variant->name, 'unit' => $variant->unit->code, 'quantity' => $line->quantity,
                'price' => $variant->price_centavos, 'total' => $gross, 'stock' => $variant->stock];
        }
        if ($includeApprovalWarnings && $cart->discount_centavos > 0 && ! $cart->approvals()->where('action', 'discount')->where('cart_version', $cart->version)->whereNull('consumed_at')->whereNull('invalidated_at')->where('expires_at', '>', now())->exists()) {
            $warnings[] = 'Discount approval expired. Request approval again or edit the cart to remove it.';
        }

        return ['version' => $cart->version, 'lines' => $lines, 'subtotal' => $subtotal,
            'discount' => $cart->discount_centavos, 'total' => max(0, $subtotal - $cart->discount_centavos), 'warnings' => $warnings];
    }

    public function hold(User $cashier, Cart $cart, int $expectedVersion): Cart
    {
        return DB::transaction(function () use ($cashier, $cart, $expectedVersion): Cart {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->editable($cashier, $cart, $expectedVersion);
            abort_unless($cart->lines()->exists(), 422, 'Add an item before holding this cart.');
            $this->invalidate($cart);
            $cart->status = 'held';
            $cart->save();
            app(AuditRecorder::class)->record($cashier, 'Cart held', 'pos', 'cart', (string) $cart->id, 'success');

            return $cart->refresh();
        }, 3);
    }

    public function resume(User $cashier, Cart $cart): Cart
    {
        return DB::transaction(function () use ($cashier, $cart): Cart {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->owned($cashier, $cart);
            abort_unless($cart->status === 'held', 409, 'This cart has already been resumed or completed.');
            $this->invalidate($cart);
            $cart->status = 'draft';
            $cart->save();
            app(AuditRecorder::class)->record($cashier, 'Cart resumed', 'pos', 'cart', (string) $cart->id, 'success');

            return $cart->refresh();
        }, 3);
    }

    public function cancel(User $cashier, Cart $cart, int $expectedVersion, string $reason, ?int $approvalId): void
    {
        abort_unless(mb_strlen(trim($reason)) >= 3 && mb_strlen($reason) <= 1000, 422, 'Enter a cancellation reason.');
        DB::transaction(function () use ($cashier, $cart, $expectedVersion, $reason, $approvalId): void {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $this->editable($cashier, $cart, $expectedVersion);
            if ($approvalId !== null) {
                $approval = Approval::find($approvalId);
                abort_unless($approval && $approval->reason === trim($reason), 409, 'A matching Manager void approval is required.');
                app(ApprovalService::class)->consume($cashier, $approval, $cart, 'cancel_exception');
            }
            $this->invalidate($cart);
            $cart->status = 'cancelled';
            $cart->cancellation_reason = trim($reason);
            $cart->save();
            app(AuditRecorder::class)->record($cashier, $approvalId ? 'Unpaid cart voided' : 'Draft cancelled', 'pos', 'cart', (string) $cart->id, 'success', ['reason' => $reason, 'approver_id' => $approvalId ? $approval->approver_id : null]);
        }, 3);
    }
}
