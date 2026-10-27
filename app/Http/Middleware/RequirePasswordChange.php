<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->force_password_change && ! $request->routeIs('password.change', 'password.update', 'logout', 'recovery.form', 'recovery.complete')) {
            return $request->expectsJson() ? response()->json(['message' => 'Choose your own passphrase before continuing.', 'redirect' => route('password.change')], 423) : redirect()->route('password.change');
        }

        return $next($request);
    }
}
