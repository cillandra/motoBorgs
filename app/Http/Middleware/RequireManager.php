<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireManager
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isManager()) {
            app(AuditRecorder::class)->record($request->user(), 'Access denied', 'security', 'route', (string) $request->route()?->getName(), 'denied');
            abort(403, 'Manager access is required.');
        }

        return $next($request);
    }
}
