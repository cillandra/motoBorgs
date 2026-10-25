<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SessionService
{
    public function signIn(User $user): void
    {
        Auth::login($user);
        request()->session()->regenerate();
        request()->session()->put(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        app(AuditRecorder::class)->record($user, 'Sign in', 'authentication', 'employee', (string) $user->id, 'success');
    }

    public function revoke(User $user, ?string $exceptSessionId = null): void
    {
        $query = DB::table('sessions')->where('user_id', $user->id);
        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }
        $query->delete();
    }
}
