<?php

namespace App\Services\Pos;

use App\Models\Approval;
use App\Models\Cart;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Settings\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class ApprovalService
{
    public function approve(User $manager, string $password, Cart $cart, int $expectedVersion, string $action, array $parameters, string $reason): Approval
    {
        $manager = $manager->fresh();
        $key = 'manager-approval:'.$manager->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many approval attempts. Try again in 15 minutes.');
        if (! $manager->isManager() || ! Hash::check($password, $manager->password)) {
            RateLimiter::hit($key, 900);
            app(AuditRecorder::class)->record($manager, 'Manager approval rejected', 'pos', 'cart', (string) $cart->id, 'denied');
            abort(422, 'Manager credentials could not be verified.');
        }
        abort_unless(in_array($action, ['discount', 'cancel_exception'], true) && mb_strlen(trim($reason)) >= 3 && mb_strlen($reason) <= 1000, 422, 'Choose a valid approval action and reason.');

        return DB::transaction(function () use ($manager, $cart, $expectedVersion, $action, $parameters, $reason): Approval {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $service = app(CartService::class);
            $cashier = $cart->cashier;
            $service->editable($cashier, $cart, $expectedVersion);
            if ($action === 'discount') {
                $quote = $service->quote($cashier, $cart, false);
                $cap = max(0, min(10, (int) (Setting::where('section', 'sales')->first()?->value['discount_cap'] ?? 10)));
                $amount = $parameters['discount_centavos'] ?? null;
                abort_unless(is_int($amount) && $amount > 0 && $amount <= intdiv($quote['subtotal'] * $cap, 100) && $quote['lines'] !== [], 422, 'Discount must be positive and within the configured '.$cap.'% cap.');
                abort_if($quote['warnings'] !== [], 409, 'Resolve the order warnings before approving a discount.');
                $service->invalidate($cart);
                $cart->discount_centavos = $amount;
                $cart->save();
                $parameters = ['discount_centavos' => $amount];
            } else {
                $parameters = [];
                $cart->approvals()->where('action', $action)->whereNull('consumed_at')->update(['invalidated_at' => now()]);
            }
            $approval = Approval::create(['cart_id' => $cart->id, 'requester_id' => $cashier->id, 'approver_id' => $manager->id,
                'cart_version' => $cart->version, 'action' => $action, 'parameters' => $parameters, 'reason' => trim($reason), 'expires_at' => now()->addMinutes(5)]);
            app(AuditRecorder::class)->record($manager, 'Manager approval granted', 'pos', 'cart', (string) $cart->id, 'success',
                ['requester_id' => $cashier->id, 'approver_id' => $manager->id, 'reason' => $reason, 'amount' => $parameters['discount_centavos'] ?? 0]);

            app(NotificationService::class)->publish('approval_decided', 'approval', (string) $approval->id);

            return $approval;
        }, 3);
    }

    public function consume(User $cashier, Approval $approval, Cart $cart, string $action): void
    {
        DB::transaction(function () use ($cashier, $approval, $cart, $action): void {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            app(CartService::class)->owned($cashier, $cart);
            $approval = Approval::whereKey($approval->id)->lockForUpdate()->firstOrFail();
            abort_unless($approval->cart_id === $cart->id && $approval->requester_id === $cashier->id && (int) $approval->cart_version === $cart->version
                && $approval->action === $action && ! $approval->invalidated_at && ! $approval->consumed_at && $approval->expires_at->isAfter(now()), 409, 'Approval expired, was already used or no longer matches this order.');
            abort_unless(User::find($approval->approver_id)?->isManager(), 409, 'The approving Manager is no longer active.');
            if ($action === 'discount') {
                abort_unless(($approval->parameters['discount_centavos'] ?? null) === $cart->discount_centavos, 409, 'Discount no longer matches approval.');
            }
            $approval->update(['consumed_at' => now()]);
        }, 3);
    }
}
