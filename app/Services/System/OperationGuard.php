<?php

namespace App\Services\System;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperationGuard
{
    private ?string $ownedMode = null;

    public function mode(): ?string
    {
        return $this->ownedMode;
    }

    public function directory(): string
    {
        $directory = config('moto_recovery.operations', base_path('.runtime'));
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the operation lock directory.');
        }

        return $directory;
    }

    public function epoch(): string
    {
        $path = $this->directory().'/session-epoch.json';

        return is_file($path) ? app(RecoveryFiles::class)->read($path)['epoch'] : 'initial';
    }

    public function transaction(Closure $operation, int $attempts = 1): mixed
    {
        return $this->write(fn () => DB::transaction($operation, $attempts));
    }

    public function write(Closure $operation): mixed
    {
        return $this->lock('shared', $operation, false);
    }

    public function administrative(Closure $operation): mixed
    {
        return $this->lock('shared', $operation, true);
    }

    public function exclusive(Closure $operation): mixed
    {
        return $this->lock('exclusive', $operation, true);
    }

    public function busy(): bool
    {
        return is_file($this->directory().'/maintenance.json') || is_file($this->directory().'/restore-journal.json');
    }

    private function lock(string $mode, Closure $operation, bool $administrative): mixed
    {
        if ($this->ownedMode !== null) {
            if ($this->ownedMode === 'shared' && $mode === 'exclusive') {
                throw new RuntimeException('A shared operation cannot upgrade to exclusive maintenance. Retry separately.');
            }
            if (! $administrative && $this->ownedMode !== 'exclusive') {
                $this->checkBusinessStatus();
            }

            return $operation();
        }
        $directory = $this->directory();
        $handle = fopen($directory.'/operations.lock', 'c+');
        if (! $handle) {
            throw new RuntimeException('Cannot open the operation lock.');
        }
        $locked = false;
        try {
            $deadline = microtime(true) + (float) config('moto_recovery.lock_timeout', 5);
            do {
                $locked = flock($handle, ($mode === 'exclusive' ? LOCK_EX : LOCK_SH) | LOCK_NB);
                if (! $locked) {
                    usleep(20000);
                }
            } while (! $locked && microtime(true) < $deadline);
            abort_unless($locked, 503, 'Another operation is active. Check its status before retrying maintenance.');
            if ($mode === 'shared') {
                abort_if(is_file($directory.'/restore-journal.json'), 503, 'Recovery must be completed before this operation. Ask the local administrator to run moto:recovery-resume.');
                if (! $administrative) {
                    $this->checkBusinessStatus();
                }
            }
            if (app()->bound('moto.request-epoch')) {
                abort_unless(app('moto.request-epoch') === $this->epoch(), 401, 'Recovery completed. Sign in again.');
            }
            $this->ownedMode = $mode;

            return $operation();
        } finally {
            if ($locked) {
                $this->ownedMode = null;
                flock($handle, LOCK_UN);
            } fclose($handle);
        }
    }

    private function checkBusinessStatus(): void
    {
        abort_if($this->busy(),503,'Maintenance is in progress. Saved records are retained; retry after the Manager finishes recovery.');
    }
}
