<?php

namespace Tests\Feature\Integration;

use App\Models\CustomerInquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InquiryRaceTest extends TestCase
{
    public function test_overlapping_identical_submissions_return_one_inquiry(): void
    {
        config(['database.connections.mysql.database' => 'moto_concurrency_test']);
        DB::purge('mysql');
        $this->assertSame('moto_concurrency_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--database' => 'mysql', '--force' => true])->assertExitCode(0);
        $directory = storage_path('framework/testing/inquiry-race-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/start';
        $key = (string) Str::uuid();
        $processes = [];
        try {
            foreach ([1, 2] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/inquiry-worker.php'), (string) $worker, $barrier, $key], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'moto_concurrency_test', 'CACHE_STORE' => 'array']);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'.ready.*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'.ready.*'));
            file_put_contents($barrier.'.go', 'go');
            $ids = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $ids[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['id'];
            }
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(1, CustomerInquiry::count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            File::deleteDirectory($directory);
            DB::disconnect('mysql');
        }
    }
}
