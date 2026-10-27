<?php

namespace App\Console\Commands;

use App\Services\System\DiagnosticsService;
use App\Services\System\OperationGuard;
use App\Services\System\RecoveryFiles;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class VerifyBackupScheduler extends Command
{
    protected $signature = 'moto:backup-scheduler {--heartbeat} {--verify}';

    protected $description = 'Record the local scheduler heartbeat or verify its Windows task installation';

    public function handle(DiagnosticsService $diagnostics, OperationGuard $guard, RecoveryFiles $files): int
    {
        $directory = $guard->directory();
        if ($this->option('heartbeat')) {
            $files->atomic($directory.'/scheduler-heartbeat.json', ['timestamp' => time()]);

            return self::SUCCESS;
        }
        if ($this->option('verify')) {
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->error('This installer verification targets Windows Task Scheduler.');

                return self::FAILURE;
            }
            $script = '$t=Get-ScheduledTask -TaskName "MOTO-Local-Backups" -ErrorAction Stop; $t.Actions | Select-Object Execute,Arguments,WorkingDirectory | ConvertTo-Json -Compress';
            $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script]);
            $process->setTimeout(10);
            $process->run();
            $action = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ! is_array($action) || strcasecmp($action['Execute'] ?? '', PHP_BINARY) !== 0 || ($action['Arguments'] ?? '') !== '"'.base_path('artisan').'" schedule:run' || strcasecmp($action['WorkingDirectory'] ?? '', base_path()) !== 0) {
                $this->error('The expected local MOTO scheduler task is not installed.');

                return self::FAILURE;
            }
            $files->atomic($directory.'/scheduler-installed.json', ['verified_at' => time()]);
        }
        $status = $diagnostics->scheduler();
        $this->line($status['label']);

        return $status['verified'] ? self::SUCCESS : self::FAILURE;
    }
}
