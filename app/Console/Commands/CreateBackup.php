<?php

namespace App\Console\Commands;

use App\Services\System\BackupService;
use App\Services\System\DiagnosticsService;
use Illuminate\Console\Command;

class CreateBackup extends Command
{
    protected $signature = 'moto:backup {--scheduled : Run only from the verified local scheduler}';

    protected $description = 'Create and verify a private local MOTO database and image snapshot';

    public function handle(BackupService $backups, DiagnosticsService $diagnostics): int
    {
        if (! $this->option('scheduled') || ! $diagnostics->scheduler()['enabled']) {
            $this->error('Automatic backups are disabled or the local scheduler is unverified. Use the Manager backup action.');

            return self::FAILURE;
        }
        app()->instance('moto.scheduled-backup', true);
        try {
            $backup = $backups->create(null);
            $this->info('Verified backup '.$backup->identifier);

            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error);
            $this->error('Backup failed; inspect the local activity and server logs.');

            return self::FAILURE;
        } finally {
            app()->forgetInstance('moto.scheduled-backup');
        }
    }
}
