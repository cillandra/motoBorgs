<?php

namespace App\Services\Settings;

use App\Models\Approval;
use App\Models\Notification;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\Variant;
use App\Services\System\OperationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function publish(string $type, string $subjectType, string $subjectId, array $safeContext = []): void
    {
        abort_unless(in_array($type, ['stock_attention', 'return_requested', 'return_decided', 'approval_decided', 'recovery', 'system'], true), 422);
        $category = match ($type) {
            'stock_attention' => 'stock','return_requested','return_decided','approval_decided' => 'requests',default => 'system'
        };
        $settings = array_replace(SettingsService::DEFAULTS['notifications'], Setting::where('section', 'notifications')->first()?->value ?? []);
        if (! $settings[$category]) {
            return;
        }
        $own = [];
        $all = false;
        $title = '';
        $message = '';
        $href = null;
        if ($type === 'stock_attention') {
            $variant = Variant::with('product')->findOrFail((int) $subjectId);
            $all = true;
            $title = $variant->stock === 0 ? 'Out of stock' : 'Low stock';
            $message = $variant->product->name.' · '.$variant->sku.' · '.$variant->stock.' left';
            $href = route('inventory.show', $variant, false);
        } elseif (in_array($type, ['return_requested', 'return_decided'], true)) {
            $return = ReturnRequest::with('sale')->findOrFail((int) $subjectId);
            $own = [$return->requester_id, $return->sale->cashier_id];
            $title = $type === 'return_requested' ? 'Return review requested' : 'Return '.($return->status === 'accepted' ? 'approved' : 'rejected');
            $message = 'RET-'.str_pad($return->id, 6, '0', STR_PAD_LEFT).' · Original sale MOTO-'.str_pad($return->sale_id, 8, '0', STR_PAD_LEFT);
            $href = route('returns.show', $return, false);
        } elseif ($type === 'approval_decided') {
            $approval = Approval::findOrFail((int) $subjectId);
            $own = [$approval->requester_id];
            $title = 'Manager approval recorded';
            $message = 'Your order approval is available. Cart changes or expiry require a new approval.';
            $href = route('pos.index', [], false);
        } elseif ($type === 'recovery') {
            $own = [(int) $subjectId];
            $title = 'Account recovery updated';
            $message = 'Account access was updated through the local recovery process.';
            $href = route('settings.index', ['section' => 'account'], false);
        } else {
            $title = 'System status updated';
            $message = 'A Manager updated '.$subjectId.' settings or system status.';
            $href = route('settings.index', ['section' => in_array($subjectId, ['security', 'backup', 'system'], true) ? $subjectId : 'system'], false);
        }
        $identity = hash('sha256', json_encode([$type, $subjectType, $subjectId, $safeContext['event_id'] ?? null], JSON_THROW_ON_ERROR));
        $context = ['title' => $title, 'message' => $message, 'href' => $href, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
        app(OperationGuard::class)->write(fn () => DB::transaction(function () use ($all, $own, $identity, $type, $context): void {
            $recipients = User::where('active', true)->when(! $all, fn ($q) => $q->where(fn ($scope) => $scope->where('role', 'manager')->orWhereIn('id', $own)))->get(['id']);
            foreach ($recipients as $recipient) {
                Notification::firstOrCreate(['event_key' => $identity.':'.$recipient->id], ['user_id' => $recipient->id, 'type' => $type, 'context' => $context]);
            }
        }));
    }

    private function scoped(User $actor): Builder
    {
        $actor = $actor->fresh();
        abort_unless($actor?->active, 403);
        $query = Notification::where('user_id', $actor->id);
        if (! $actor->isManager()) {
            $query->where(function ($visible) use ($actor): void {
                $visible->where('type', 'stock_attention')
                    ->orWhere(fn ($q) => $q->where('type', 'recovery')->where('context->subject_id', (string) $actor->id))
                    ->orWhere(fn ($q) => $q->whereIn('type', ['return_requested', 'return_decided'])->whereIn('context->subject_id', ReturnRequest::whereHas('sale', fn ($sale) => $sale->where('cashier_id', $actor->id))->select('id')))
                    ->orWhere(fn ($q) => $q->where('type', 'approval_decided')->whereIn('context->subject_id', Approval::where('requester_id', $actor->id)->select('id')));
            });
        }

        return $query;
    }

    public function forUser(User $actor): Collection
    {
        return $this->scoped($actor)->latest('id')->limit(50)->get()->each(function (Notification $notification): void {
            $context = array_intersect_key($notification->context ?? [], array_flip(['title', 'message', 'href', 'subject_type', 'subject_id']));
            if (! is_string($context['href'] ?? null) || ! str_starts_with($context['href'], '/') || str_starts_with($context['href'], '//')) {
                $context['href'] = null;
            }
            $notification->context = $context;
        });
    }

    public function unreadCount(User $actor): int
    {
        return $this->scoped($actor)->whereNull('read_at')->count();
    }

    public function read(User $actor, int $id): void
    {
        app(OperationGuard::class)->write(function () use ($actor, $id): void {
            $notification = $this->scoped($actor)->findOrFail($id);
            $notification->update(['read_at' => $notification->read_at ?? now()]);
        });
    }

    public function readAll(User $actor): void
    {
        app(OperationGuard::class)->write(fn () => $this->scoped($actor)->whereNull('read_at')->update(['read_at' => now()]));
    }
}
