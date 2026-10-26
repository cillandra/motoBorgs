<?php

namespace App\Services\Inventory;

use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StockLedger
{
    public function post(Variant $variant, int $delta, string $type, string $operationKey, ?User $actor, string $reason, array $context = []): StockMovement
    {
        abort_unless(in_array($type, ['opening', 'receive', 'count_adjustment', 'sale', 'return'], true), 422, 'Unknown stock movement.');
        abort_unless(strlen($operationKey) > 0 && strlen($operationKey) <= 191 && trim($reason) !== '' && abs($delta) <= 1000000, 422, 'Invalid stock movement.');
        if (in_array($type, ['receive', 'count_adjustment', 'return'], true)) {
            abort_unless($actor?->isManager(), 403, 'Manager approval is required.');
        }
        try {
            return DB::transaction(function () use ($variant, $delta, $type, $operationKey, $actor, $reason, $context): StockMovement {
                $locked = Variant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
                $existing = StockMovement::where('operation_key', $operationKey)->first();
                if ($existing) {
                    abort_unless($existing->variant_id === $locked->id && $existing->delta === $delta && $existing->type === $type && (string) $existing->actor_id === (string) $actor?->id && $existing->reason === trim($reason) && ($existing->context ?? []) == $context, 409, 'This operation key belongs to a different movement.');

                    return $existing;
                }
                $balance = $locked->stock + $delta;
                abort_if($balance < 0, 409, 'Not enough stock remains for this operation.');
                abort_if($balance > 1000000, 422, 'Stock exceeds the supported quantity limit.');
                $locked->update(['stock' => $balance, 'version' => $locked->version + 1]);
                $movement = StockMovement::create([
                    'variant_id' => $locked->id, 'actor_id' => $actor?->id, 'delta' => $delta,
                    'balance_after' => $balance, 'type' => $type, 'operation_key' => $operationKey,
                    'reference' => $context['reference'] ?? null, 'reason' => trim($reason), 'context' => $context,
                    'occurred_at' => ! empty($context['date']) ? CarbonImmutable::parse($context['date'], 'Asia/Manila')->startOfDay()->utc() : now(),
                ]);
                $label = match ($type) {
                    'receive' => 'Stock received','count_adjustment' => 'Stock adjusted','sale' => 'Stock sold','return' => 'Stock returned',default => 'Opening stock'
                };
                app(AuditRecorder::class)->record($actor, $label, 'inventory', 'variant', (string) $locked->id, 'success', ['reason' => $reason, 'reference' => $context['reference'] ?? null, 'delta' => $delta, 'balance' => $balance]);

                return $movement;
            }, 3);
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                abort(409, 'This operation key is already in use.');
            }
            throw $error;
        }
    }

    public function receive(User $actor, Variant $variant, int $quantity, string $reference, string $reason, string $operationKey, ?string $date = null): StockMovement
    {
        abort_unless($actor->isManager(), 403);
        abort_unless($quantity > 0 && trim($reference) !== '', 422, 'Enter a positive quantity and reference.');

        return DB::transaction(function () use ($actor, $variant, $quantity, $reference, $reason, $operationKey, $date): StockMovement {
            $locked = Variant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->archived_at || $locked->product->archived_at, 409, 'Restore the product before receiving stock.');
            $context = ['reference' => trim($reference)];
            if ($date !== null) {
                $context['date'] = $date;
            }

            return $this->post($locked, $quantity, 'receive', $operationKey, $actor, $reason, $context);
        }, 3);
    }

    public function adjustToCount(User $actor, Variant $variant, int $count, string $reason, string $operationKey, ?int $expectedVersion = null): StockMovement
    {
        abort_unless($actor->isManager(), 403);
        abort_unless($count >= 0 && $count <= 1000000, 422, 'Enter a valid whole-unit count.');

        return DB::transaction(function () use ($actor, $variant, $count, $reason, $operationKey, $expectedVersion): StockMovement {
            $locked = Variant::whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $existing = StockMovement::where('operation_key', $operationKey)->first();
            if ($existing) {
                abort_unless($existing->variant_id === $locked->id && $existing->type === 'count_adjustment' && ($existing->context['count'] ?? null) === $count && $existing->actor_id === $actor->id && $existing->reason === trim($reason), 409, 'This operation key belongs to a different adjustment.');

                return $existing;
            }
            abort_if($locked->archived_at || $locked->product->archived_at, 409, 'Restore the product before adjusting stock.');
            abort_if($expectedVersion !== null && $locked->version !== $expectedVersion, 409, 'Stock changed while this count form was open. Review the latest balance and count again.');

            return $this->post($locked, $count - $locked->stock, 'count_adjustment', $operationKey, $actor, $reason, ['count' => $count]);
        }, 3);
    }
}
