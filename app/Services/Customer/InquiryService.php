<?php

namespace App\Services\Customer;

use App\Models\CustomerInquiry;
use App\Models\User;
use App\Models\Variant;
use App\Services\Audit\AuditRecorder;
use App\Services\System\OperationGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InquiryService
{
    public function submit(array $input): CustomerInquiry
    {
        foreach (['name', 'email', 'phone', 'service_name', 'message'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, [
            'submission_key' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:/^(?=(?:[^0-9]*[0-9]){7,15}[^0-9]*$)[+()0-9 .-]{7,40}$/D'],
            'topic' => ['required', 'in:general,product,service'],
            'variant_id' => ['nullable', 'required_if:topic,product', 'integer'],
            'service_name' => ['nullable', 'required_if:topic,service', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'website' => ['nullable', 'prohibited'],
        ])->validate();
        $payload = [
            'name' => trim($data['name']), 'email' => trim($data['email'] ?? '') ?: null,
            'phone' => trim($data['phone'] ?? '') ?: null, 'topic' => $data['topic'],
            'variant_id' => $data['topic'] === 'product' ? (int) $data['variant_id'] : null,
            'service_name' => $data['topic'] === 'service' ? $data['service_name'] : null,
            'message' => trim($data['message']),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return app(OperationGuard::class)->write(function () use ($data, $payload, $hash): CustomerInquiry {
            try {
                return DB::transaction(function () use ($data, $payload, $hash): CustomerInquiry {
                    $existing = CustomerInquiry::where('submission_key', $data['submission_key'])->first();
                    if ($existing) {
                        abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'This submission changed. Open a new inquiry and try again.');

                        return $existing;
                    }
                    $subject = 'General inquiry';
                    if ($payload['topic'] === 'product') {
                        $variant = Variant::with('product')->whereKey($payload['variant_id'])->whereNull('archived_at')->whereHas('product', fn ($query) => $query->whereNull('archived_at'))->first();
                        if (! $variant) {
                            throw ValidationException::withMessages(['variant_id' => 'This product is no longer listed. Choose another product or send a general inquiry.']);
                        }
                        $subject = $variant->product->name.' · '.$variant->name;
                    } elseif ($payload['topic'] === 'service') {
                        if (! in_array($payload['service_name'], array_column(app(StorefrontService::class)->services(), 'name'), true)) {
                            throw ValidationException::withMessages(['service_name' => 'This service is no longer listed. Send a general inquiry instead.']);
                        }
                        $subject = $payload['service_name'];
                    }

                    return CustomerInquiry::create(['submission_key' => $data['submission_key']] + $payload + ['payload_hash' => $hash, 'subject' => $subject, 'status' => 'new', 'version' => 1]);
                }, 3);
            } catch (QueryException $error) {
                if (($error->errorInfo[1] ?? null) === 1062) {
                    $existing = CustomerInquiry::where('submission_key', $data['submission_key'])->first();
                    if ($existing) {
                        abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'This submission changed. Open a new inquiry and try again.');

                        return $existing;
                    }
                }
                throw $error;
            }
        });
    }

    public function handle(User $actor, CustomerInquiry $inquiry, int $version, string $status): CustomerInquiry
    {
        return app(OperationGuard::class)->transaction(function () use ($actor, $inquiry, $version, $status): CustomerInquiry {
            $actor = $actor->fresh();
            abort_unless($actor?->active && in_array($actor->role, ['manager', 'staff'], true), 403);
            abort_unless(in_array($status, ['new', 'handled'], true), 422);
            $record = CustomerInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->version === $version, 409, 'Another employee updated this inquiry. Reload the inbox before saving.');
            $record->update(['status' => $status, 'handled_by' => $status === 'handled' ? $actor->id : null, 'handled_at' => $status === 'handled' ? now() : null, 'version' => $version + 1]);
            app(AuditRecorder::class)->record($actor, 'Customer inquiry updated', 'customer_inquiries', 'customer_inquiry', (string) $record->id, 'success', ['status' => $status]);

            return $record;
        });
    }
}
