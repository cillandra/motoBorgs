<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class Reauthentication
{
    public function confirm(User $actor, string $password, string $purpose): void
    {
        $actor = $actor->fresh();
        $key = 'moto-reauth:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many verification attempts. Try again in 15 minutes.');
        if (! $actor->active || ! Hash::check($password, $actor->password)) {
            RateLimiter::hit($key, 900);
            app(AuditRecorder::class)->record($actor, 'Reauthentication rejected', 'security', 'user', (string) $actor->id, 'denied', ['reason' => $purpose]);
            abort(422, 'Your current password could not be verified.');
        }
        RateLimiter::clear($key);
    }
}
