<?php

namespace App\Services\Sales;

use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Models\Variant;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\StockLedger;
use App\Services\System\OperationGuard;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReturnService
{
    /** @param array<int,int> $lineQuantities */
    public function request(User $actor, Sale $sale, array $lineQuantities, string $reason): ReturnRequest
    {
        Gate::forUser($actor->fresh())->authorize('view', $sale);
        abort_unless(mb_strlen(trim($reason)) >= 3 && mb_strlen($reason) <= 1000 && count($lineQuantities) >= 1 && count($lineQuantities) <= 100, 422, 'Select at least one item and enter a return reason.');

        return app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($actor, $sale, $lineQuantities, $reason): ReturnRequest {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $lines = $sale->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lineQuantities as $id => $quantity) {
                $line = $lines->get($id);
                abort_unless($line && is_int($quantity) && $quantity > 0 && $quantity <= $line->quantity, 422, 'Enter a valid quantity from this original sale.');
                $pending = $line->returnLines()->whereHas('request', fn ($q) => $q->where('status', 'pending'))->exists();
                abort_if($pending, 409, 'This item already has a pending return request. Review that request first.');

                $unavailable = (int) $line->returnLines()->whereHas('request', fn ($q) => $q->whereIn('status', ['pending', 'accepted']))->sum('quantity');
                abort_if($quantity > $line->quantity - $unavailable, 409, 'These units already have a pending or accepted return request.');
            }
            $request = ReturnRequest::create(['sale_id' => $sale->id, 'requester_id' => $actor->id, 'reason' => trim($reason)]);
            foreach ($lineQuantities as $id => $quantity) {
                $request->lines()->create(['sale_line_id' => $id, 'quantity' => $quantity]);
            }
            app(AuditRecorder::class)->record($actor, 'Return requested', 'sales', 'return', (string) $request->id, 'success', ['sale_id' => $sale->id, 'reason' => $reason]);

            return $request->fresh(['lines.saleLine', 'sale']);
        }, 3));
    }

    public function review(User $manager, ReturnRequest $request, array $conditions, bool $accept, string $reason): ReturnRequest
    {
        abort_unless($manager->fresh()->isManager(), 403);
        abort_unless(mb_strlen(trim($reason)) >= 3 && mb_strlen($reason) <= 1000, 422, 'Enter an inspection or rejection reason.');

        return app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($manager, $request, $conditions, $accept, $reason): ReturnRequest {
            $sale = Sale::whereKey($request->sale_id)->lockForUpdate()->firstOrFail();
            $request = ReturnRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $lines = $request->lines()->orderBy('sale_line_id')->lockForUpdate()->get();
            if ($request->status !== 'pending') {
                $matches = $request->status === ($accept ? 'accepted' : 'rejected') && $request->review_reason === trim($reason);
                foreach ($lines as $line) {
                    if ($accept && ($conditions[$line->id]['restock_quantity'] ?? null) !== $line->restock_quantity) {
                        $matches = false;
                    }
                }
                abort_unless($matches, 409, 'This request has already been reviewed with a different decision.');

                return $request->fresh(['lines.saleLine', 'refunds']);
            }
            $saleLines = $sale->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($accept) {
                abort_unless(count($conditions) === $lines->count(), 422, 'Inspect every requested item.');
                $variantIds = $lines->map(fn ($line) => $saleLines[$line->sale_line_id]->variant_id)->unique()->sort()->values()->all();
                Variant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
                foreach ($lines as $line) {
                    $restock = $conditions[$line->id]['restock_quantity'] ?? null;
                    abort_unless(is_int($restock) && $restock >= 0 && $restock <= $line->quantity, 422, 'Sellable quantity must be between zero and the returned quantity.');
                    $original = $saleLines[$line->sale_line_id];
                    $accepted = (int) $original->returnLines()->whereHas('request', fn ($q) => $q->where('status', 'accepted'))->sum('quantity');
                    abort_if($accepted + $line->quantity > $original->quantity, 409, 'Another return already accepted these units.');
                    $amount = Money::refundForUnits($original->quantity, $original->price_centavos, $original->discount_centavos, $accepted, $line->quantity);
                    $line->update(['restock_quantity' => $restock, 'condition' => $restock === 0 ? 'damaged' : ($restock === $line->quantity ? 'sellable' : 'mixed'), 'refundable_centavos' => $amount]);
                    if ($restock > 0) {
                        app(StockLedger::class)->post(Variant::findOrFail($original->variant_id), $restock, 'return', 'return:'.$request->id.':line:'.$line->id, $manager, 'Accepted sellable return', ['sale_id' => $sale->id]);
                    }
                }
            }
            $request->update(['reviewer_id' => $manager->id, 'status' => $accept ? 'accepted' : 'rejected',
                'review_reason' => trim($reason), 'accepted_at' => $accept ? now() : null]);
            app(AuditRecorder::class)->record($manager, $accept ? 'Return accepted' : 'Return rejected', 'sales', 'return', (string) $request->id, 'success',
                ['sale_id' => $sale->id, 'requester_id' => $request->requester_id, 'reason' => $reason]);

            return $request->fresh(['lines.saleLine', 'refunds']);
        }, 3));
    }

    public function quantities(SaleLine $line): array
    {
        return ['accepted' => (int) $line->returnLines()->whereHas('request', fn ($q) => $q->where('status', 'accepted'))->sum('quantity'),
            'pending' => (int) $line->returnLines()->whereHas('request', fn ($q) => $q->where('status', 'pending'))->sum('quantity')];
    }
}
