<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, SessionService $sessions): RedirectResponse
    {
        $username = mb_strtolower(trim($request->string('username')->toString()));
        $key = 'login:'.hash('sha256', $username.'|'.$request->ip());
        $policy = Setting::where('section', 'security')->first()?->value ?? [];
        if (RateLimiter::tooManyAttempts($key, $policy['failed_attempts'] ?? 5) || RateLimiter::tooManyAttempts('login-ip:'.$request->ip(), 50)) {
            abort(429, 'Too many sign-in attempts. Wait before trying again.');
        }
        $user = User::where('username', $username)->first();
        if (! $user || ! $user->active || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($key, ($policy['cooldown_minutes'] ?? 15) * 60);
            RateLimiter::hit('login-ip:'.$request->ip(), 900);
            app(AuditRecorder::class)->record($user, 'Sign in failed', 'authentication', 'employee', $user ? (string) $user->id : hash('sha256', $username), 'denied');

            return back()->withErrors(['username' => 'Unable to sign in with those credentials.'])->withInput(['username' => $username]);
        }
        RateLimiter::clear($key);
        $sessions->signIn($user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($user) {
            app(AuditRecorder::class)->record($user, 'Sign out', 'authentication', 'employee', (string) $user->id, 'success');
        }

        return redirect()->route('login');
    }
}
