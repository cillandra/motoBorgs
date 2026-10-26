<?php

namespace App\Services\System;

use Closure;
use RuntimeException;

class OperationGuard
{
    private int $depth = 0;

    public function write(Closure $operation): mixed
    {
        if ($this->depth > 0) {
            return $operation();
        }
        $directory = base_path('.runtime');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the operation lock directory.');
        }
        $handle = fopen($directory.'/operations.lock', 'c+');
        if (! $handle) {
            throw new RuntimeException('Cannot open the operation lock.');
        }
        $locked = false;
        try {
            $deadline = microtime(true) + 5;
            do {
                $locked = flock($handle, LOCK_SH | LOCK_NB);
                if (! $locked) {
                    usleep(20000);
                }
            } while (! $locked && microtime(true) < $deadline);
            abort_unless($locked, 503, 'Maintenance is in progress. Check the operation status before retrying.');
            abort_if(is_file($directory.'/maintenance.json'), 503, 'Maintenance is in progress. Saved records are retained.');
            $this->depth++;

            return $operation();
        } finally {
            if ($locked) {
                $this->depth = 0;
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }
}
