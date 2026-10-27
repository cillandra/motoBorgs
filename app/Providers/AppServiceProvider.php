<?php

namespace App\Providers;

use App\Services\System\GuardedSessionHandler;
use App\Services\System\OperationGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationGuard::class);
    }

    public function boot(): void
    {
        $this->app['session']->extend('guarded_database', fn ($app) => new GuardedSessionHandler($app['db']->connection(config('session.connection')), config('session.table'), config('session.lifetime'), $app));
    }
}
