<?php

use App\Models\Setting;
use App\Services\System\DiagnosticsService;
use Illuminate\Support\Facades\Schedule;

Schedule::command('moto:backup-scheduler --heartbeat')->everyMinute()->withoutOverlapping();
Schedule::command('moto:backup --scheduled')->everyMinute()->when(fn () => now('Asia/Manila')->format('H:i') === (Setting::where('section', 'backup')->first()?->value['schedule'] ?? '20:00') && app(DiagnosticsService::class)->scheduler()['enabled'])->withoutOverlapping();
