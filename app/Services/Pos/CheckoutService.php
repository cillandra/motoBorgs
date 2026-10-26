<?php

namespace App\Services\Pos;

use App\Models\Approval;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Models\Variant;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\StockLedger;
use App\Services\System\OperationGuard;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function complete(User $cashier, Cart $cart, int $expectedVersion, string $requestKey, array $payment): CheckoutResult
    {
        abort_unless($cashier->fresh()->active, 403);
        abort_unless(strlen($requestKey) > 0 && strlen($requestKey) <= 80, 422, 'Invalid checkout request key.');
        $payment = $this->normalize($payment);
        $hash = hash('sha256', json_encode(['cashier_id' => $cashier->id, 'cart_id' => $cart->id, 'version' => $expectedVersion, 'payment' => $payment], JSON_THROW_ON_ERROR));

        return app(OperationGuard::class)->write(function () use ($cashier, $cart, $expectedVersion, $requestKey, $payment, $hash): CheckoutResult {
            try {
                return DB::transaction(function () use ($cashier, $cart, $expectedVersion, $requestKey, $payment, $hash): CheckoutResult {
                    $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
                    app(CartService::class)->owned($cashier, $cart);
                    $existing = Sale::where('request_key', $requestKey)->lockForUpdate()->first();
                    if ($existing) {
                        return $this->replay($existing, $cashier, $hash);
                    }
                    app(CartService::class)->editable($cashier, $cart, $expectedVersion);
                    abort_if(Sale::where('cart_id', $cart->id)->exists(), 409, 'This cart already has a completed sale.');
                    $lines = $cart->lines()->orderBy('variant_id')->get();
                    abort_if($lines->isEmpty(), 422, 'Add an item before checkout.');
                    $ids = $lines->pluck('variant_id')->all();
                    $productIds = Variant::whereIn('id', $ids)->pluck('product_id')->unique()->sort()->values()->all();
                    $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $variants = Variant::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $gross = [];
                    foreach ($lines as $line) {
                        $variant = $variants->get($line->variant_id);
                        $product = $variant ? $products->get($variant->product_id) : null;
                        abort_unless($variant && $product && ! $variant->archived_at && ! $product->archived_at, 409, 'A selected product is no longer available.');
                        abort_unless($line->quantity > 0 && $line->quantity <= $variant->stock, 409, $variant->sku.': insufficient stock. Review the current order.');
                        abort_unless($line->price_centavos === $variant->price_centavos, 409, $variant->sku.': price changed. Review and acknowledge current prices.');
                        $gross[$line->id] = $line->quantity * $variant->price_centavos;
                    }
                    $subtotal = array_sum($gross);
                    abort_if($subtotal > Money::MAX_CENTAVOS, 422, 'Order exceeds the transaction amount limit.');
                    $discount = $cart->discount_centavos;
                    abort_if($discount < 0 || $discount > intdiv($subtotal, 10), 409, 'Discount exceeds the approved policy.');
                    $approval = null;
                    if ($discount > 0) {
                        $approval = Approval::where('cart_id', $cart->id)->where('cart_version', $cart->version)->where('action', 'discount')->whereNull('invalidated_at')->whereNull('consumed_at')->latest('id')->first();
                        abort_unless($approval, 409, 'A current Manager discount approval is required.');
                        app(ApprovalService::class)->consume($cashier, $approval, $cart, 'discount');
                    }
                    $total = $subtotal - $discount;
                    $enabled = Setting::where('section', 'sales')->value('value');
                    $enabled = is_string($enabled) ? json_decode($enabled, true) : $enabled;
                    abort_unless(in_array($payment['method'], $enabled['methods'] ?? ['cash', 'gcash', 'qrph', 'debit', 'credit'], true), 422, 'This payment method is disabled.');
                    if ($payment['method'] === 'cash') {
                        abort_if($payment['received_centavos'] < $total, 422, 'Cash received does not cover the total.');
                    } else {
                        abort_unless($payment['verified'] && $payment['reference'] !== '' && $payment['received_centavos'] === $total, 422, 'Verify the external payment, enter its non-sensitive reference and record the exact amount due.');
                    }
                    $sale = Sale::create(['cart_id' => $cart->id, 'cashier_id' => $cashier->id, 'cashier_name' => $cashier->name,
                        'request_key' => $requestKey, 'request_hash' => $hash, 'subtotal_centavos' => $subtotal,
                        'discount_centavos' => $discount, 'tax_centavos' => 0, 'tax_mode' => 'demo_unconfigured', 'total_centavos' => $total, 'approval_id' => $approval?->id]);
                    $allocation = Money::allocate($discount, $gross);
                    foreach ($lines as $line) {
                        $variant = $variants[$line->variant_id];
                        $product = $products[$variant->product_id];
                        $saleLine = $sale->lines()->create(['variant_id' => $variant->id, 'category_id_snapshot' => $product->category_id,
                            'category_name' => $product->category?->name, 'sku' => $variant->sku, 'product_name' => $product->name,
                            'variant_name' => $variant->name, 'unit' => $variant->unit->code, 'quantity' => $line->quantity,
                            'price_centavos' => $variant->price_centavos, 'discount_centavos' => $allocation[$line->id], 'total_centavos' => $gross[$line->id] - $allocation[$line->id]]);
                        app(StockLedger::class)->post($variant, -$line->quantity, 'sale', 'sale:'.$sale->id.':line:'.$saleLine->id, $cashier, 'Completed sale', ['sale_id' => $sale->id]);
                    }
                    $sale->payment()->create(['method' => $payment['method'], 'amount_centavos' => $total,
                        'received_centavos' => $payment['received_centavos'], 'change_centavos' => $payment['method'] === 'cash' ? $payment['received_centavos'] - $total : 0,
                        'verified' => true, 'reference' => $payment['method'] === 'cash' ? null : $payment['reference']]);
                    $cart->update(['status' => 'completed', 'version' => $cart->version + 1]);
                    app(AuditRecorder::class)->record($cashier, 'Sale completed', 'sales', 'sale', (string) $sale->id, 'success',
                        ['cart_id' => $cart->id, 'amount' => $total, 'method' => $payment['method'], 'approver_id' => $approval?->approver_id]);

                    return new CheckoutResult($sale->fresh(['lines', 'payment']), false);
                }, 3);
            } catch (QueryException $error) {
                if (($error->errorInfo[1] ?? null) === 1062) {
                    $existing = Sale::where('request_key', $requestKey)->first();
                    if ($existing) {
                        return $this->replay($existing, $cashier, $hash);
                    }
                    abort(409, 'The cart already has a completed checkout. Check its sale status.');
                }
                throw $error;
            }
        });
    }

    private function normalize(array $payment): array
    {
        $method = $payment['method'] ?? '';
        $received = $payment['received_centavos'] ?? null;
        abort_unless(in_array($method, ['cash', 'gcash', 'qrph', 'debit', 'credit'], true) && is_int($received) && $received >= 0 && $received <= Money::MAX_CENTAVOS, 422, 'Enter a valid payment method and amount.');
        $reference = trim((string) ($payment['reference'] ?? ''));
        abort_if(mb_strlen($reference) > 100 || preg_match('/[\x00-\x1F\x7F]/', $reference), 422, 'Enter a valid non-sensitive payment reference.');

        return ['method' => $method, 'received_centavos' => $received, 'reference' => $method === 'cash' ? null : $reference,
            'verified' => $method === 'cash' || ($payment['verified'] ?? false) === true];
    }

    private function replay(Sale $sale, User $cashier, string $hash): CheckoutResult
    {
        abort_unless($sale->cashier_id === $cashier->id && hash_equals($sale->request_hash, $hash), 409, 'This request key belongs to a different checkout. Check the original attempt status.');

        return new CheckoutResult($sale->load(['lines', 'payment']), true);
    }

    public function status(User $cashier, string $requestKey): ?Sale
    {
        abort_unless($cashier->active, 403);

        return Sale::where('cashier_id', $cashier->id)->where('request_key', $requestKey)->first();
    }
}
