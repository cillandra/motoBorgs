<?php

namespace App\Services\Sales;

use App\Models\RefundRecord;
use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\System\OperationGuard;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function recordOutcome(User $manager, ReturnRequest $request, string $operationKey, int $amount, string $status, string $reference): RefundRecord
    {
        abort_unless($manager->fresh()->isManager(), 403);
        $reference = trim($reference);
        abort_unless(in_array($status, ['pending', 'failed', 'paid'], true) && strlen($operationKey) > 0 && strlen($operationKey) <= 191
            && $amount > 0 && $amount <= Money::MAX_CENTAVOS && $reference !== '' && mb_strlen($reference) <= 100 && ! preg_match('/[\x00-\x1F\x7F]/', $reference), 422, 'Enter a valid payout amount, status and tracking reference.');
        try {
            return app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($manager, $request, $operationKey, $amount, $status, $reference): RefundRecord {
                $sale = Sale::whereKey($request->sale_id)->lockForUpdate()->firstOrFail();
                $request = ReturnRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                abort_unless($request->status === 'accepted', 409, 'Approve the return before recording a refund payout.');
                $existing = RefundRecord::where('operation_key', $operationKey)->lockForUpdate()->first();
                if ($existing) {
                    abort_unless($existing->return_request_id === $request->id && $existing->amount_centavos === $amount && $existing->reference === $reference, 409, 'This payout key belongs to different details. Check the existing payout status.');
                    if ($existing->status === $status) {
                        return $existing;
                    }
                    abort_if($existing->status === 'paid', 409, 'This payout is already paid and cannot be changed.');
                }
                abort_if(RefundRecord::where('return_request_id', $request->id)->where('reference', $reference)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists(), 409, 'This tracking reference already belongs to a payout. Update that existing attempt.');
                $eligible = (int) $request->lines()->sum('refundable_centavos');
                $reserved = (int) $request->refunds()->whereIn('status', ['pending', 'paid'])->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->sum('amount_centavos');
                $saleReserved = (int) RefundRecord::whereHas('request', fn ($q) => $q->where('sale_id', $sale->id))->whereIn('status', ['pending', 'paid'])
                    ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->sum('amount_centavos');
                abort_if($amount > $eligible - $reserved || $amount > $sale->total_centavos - $saleReserved, 409, 'Payout exceeds the remaining refundable value, including pending payouts.');
                $record = $existing ?? new RefundRecord(['return_request_id' => $request->id, 'operation_key' => $operationKey, 'amount_centavos' => $amount, 'reference' => $reference]);
                $record->fill(['manager_id' => $manager->id, 'status' => $status, 'paid_at' => $status === 'paid' ? now() : null])->save();
                app(AuditRecorder::class)->record($manager, 'Refund payout '.$status, 'sales', 'refund', (string) $record->id, 'success',
                    ['sale_id' => $sale->id, 'amount' => $amount, 'status' => $status, 'reference' => $reference]);

                return $record->fresh();
            }, 3));
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                abort(409, 'This payout attempt is already recorded. Check its current status.');
            }
            throw $error;
        }
    }
}
