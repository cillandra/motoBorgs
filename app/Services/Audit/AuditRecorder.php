<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\System\OperationGuard;

class AuditRecorder
{
    /** @param array<string, mixed> $safeContext */
    public function record(?User $actor, string $type, string $module, string $subjectType, string $subjectId, string $outcome, array $safeContext = []): AuditEvent
    {
        $allowed = array_intersect_key($safeContext, array_flip(['reason', 'reference', 'quantity', 'delta', 'balance', 'method', 'amount', 'requester_id', 'approver_id', 'changes', 'cart_id', 'sale_id', 'variant_id', 'status', 'backup_id']));

        return app(OperationGuard::class)->administrative(fn () => AuditEvent::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'System',
            'type' => $type,
            'module' => $module,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'outcome' => $outcome,
            'context' => $this->redact($allowed),
        ]));
    }

    public function safeContext(array $context): array
    {
        return $this->redact(array_intersect_key($context, array_flip(['reason', 'reference', 'quantity', 'delta', 'balance', 'method', 'amount', 'requester_id', 'approver_id', 'changes', 'cart_id', 'sale_id', 'variant_id', 'status', 'backup_id'])));
    }

    /** @param array<array-key, mixed> $values @return array<array-key, mixed> */
    private function redact(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret|card|session|credential|database/i', $key)) {
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 2000) : $value;
            }
        }

        return $safe;
    }
}
