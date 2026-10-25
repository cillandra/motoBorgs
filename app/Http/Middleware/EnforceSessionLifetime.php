<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        $policy = Setting::where('section', 'security')->first()?->value ?? [];
        $signedIn = $request->session()->get('moto_signed_in_at');
        $lastActivity = $request->session()->get('moto_last_activity_at');
        $expired = ! is_int($signedIn) || ! is_int($lastActivity)
            || now()->timestamp - $signedIn >= ($policy['absolute_hours'] ?? 8) * 3600
            || now()->timestamp - $lastActivity >= ($policy['idle_minutes'] ?? 15) * 60;
        if ($expired) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Your session has expired. Sign in again.'], 401) : redirect()->route('login');
        }
        $request->session()->put('moto_last_activity_at', now()->timestamp);

        return $next($request);
    }
}
