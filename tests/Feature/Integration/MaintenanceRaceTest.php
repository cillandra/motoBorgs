<?php

namespace Tests\Feature\Integration;

use App\Services\System\OperationGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MaintenanceRaceTest extends TestCase
{
    public function test_independent_write_and_exclusive_owners_block_each_other_through_nested_calls(): void
    {
        $directory = storage_path('framework/testing/guard-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        config(['moto_recovery.operations' => $directory, 'moto_recovery.lock_timeout' => 0.1]);
        app()->forgetInstance(OperationGuard::class);
        try {
            $guard = app(OperationGuard::class);
            foreach (['write' => 'exclusive', 'exclusive' => 'write'] as $owned => $contender) {
                $guard->$owned(function () use ($guard, $contender, $directory): void {
                    if ($contender === 'write') {
                        $guard->exclusive(fn () => $guard->write(fn () => null));
                    }
                    $process = new Process([PHP_BINARY, base_path('tests/Support/guard-worker.php'), $contender, $directory], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'moto_concurrency_test']);
                    $process->setTimeout(10);
                    $process->run();
                    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                    $result = json_decode($process->getOutput(), true);
                    $this->assertSame(503, $result['status']);
                });
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
