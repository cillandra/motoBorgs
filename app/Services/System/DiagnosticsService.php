<?php

namespace App\Services\System;

use App\Models\BackupManifest;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class DiagnosticsService
{
    public function scheduler(): array
    {
        $directory = app(OperationGuard::class)->directory();
        $installed = is_file($directory.'/scheduler-installed.json');
        $heartbeat = null;
        try {
            if ($installed) {
                app(RecoveryFiles::class)->read($directory.'/scheduler-installed.json');
            } if (is_file($directory.'/scheduler-heartbeat.json')) {
                $heartbeat = app(RecoveryFiles::class)->read($directory.'/scheduler-heartbeat.json')['timestamp'];
            }
        } catch (\Throwable) {
            $installed = false;
        }
        $verified = $installed && $heartbeat !== null && $heartbeat <= time() + 60 && $heartbeat >= time() - 180;
        $enabled = (bool) (Setting::where('section', 'backup')->first()?->value['enabled'] ?? false);
        $latest = BackupManifest::where('status', 'complete')->latest('completed_at')->first();
        $latestAttempt = BackupManifest::latest('id')->first();

        return ['installed' => $installed, 'verified' => $verified, 'enabled' => $enabled && $verified, 'heartbeat' => $heartbeat, 'label' => ! $installed ? 'Off · local scheduler not installed' : (! $verified ? 'Off · scheduler heartbeat not verified' : ($enabled ? 'On · local scheduler verified' : 'Off · scheduler verified, automatic backups disabled')), 'missed' => $enabled && $verified && (! $latest || $latest->completed_at->lt(now()->subHours(26))), 'failed' => $latestAttempt?->status === 'failed'];
    }

    public function facts(bool $manager): array
    {
        $facts = ['Application' => 'MOTO · School demonstration', 'PHP' => PHP_VERSION, 'Laravel' => app()->version(), 'Business timezone' => 'Asia/Manila', 'Branch scope' => 'One branch', 'Payments' => 'Records with manual verification', 'Tax' => 'Unconfigured · academic receipt'];
        if ($manager) {
            try {
                DB::select('SELECT 1');
                $facts['Database'] = 'Available';
            } catch (\Throwable) {
                $facts['Database'] = 'Unavailable';
            }
            $facts['Image processing'] = extension_loaded('gd') ? 'Available' : 'Unavailable';
            $facts['Archive support'] = extension_loaded('zip') ? 'Available' : 'Unavailable';
            $facts['Private storage'] = is_writable(storage_path('app/private')) ? 'Writable' : 'Unavailable';
            $free = disk_free_space(storage_path());
            $facts['Free disk space'] = $free === false ? 'Unavailable' : number_format($free / 1073741824, 1).' GB';
            $facts['Measured at'] = now()->timezone('Asia/Manila')->format('d M Y, H:i:s');
        }

        return $facts;
    }
}
