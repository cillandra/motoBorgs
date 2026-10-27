<?php

use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\RequireManager;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePasswordChange::class);
        $middleware->appendToGroup('web', RequirePasswordChange::class);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'session.lifetime' => EnforceSessionLifetime::class,
            'manager' => RequireManager::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['manager_password', 'current_password', 'token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
