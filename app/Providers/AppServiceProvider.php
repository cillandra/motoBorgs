<?php

namespace App\Providers;

use App\Services\System\OperationGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperationGuard::class);
    }

    public function boot(): void {}
}
