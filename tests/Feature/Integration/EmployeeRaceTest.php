<?php

namespace Tests\Feature\Integration;

use App\Models\User;
use App\Services\Identity\EmployeeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class EmployeeRaceTest extends TestCase
{
    use MotoFixtures;

    public function test_simultaneous_manager_demotions_cannot_remove_the_last_manager(): void
    {
        $this->assertTrue(method_exists(EmployeeService::class, 'save'));
        config(['database.connections.mysql.database' => 'moto_concurrency_test']);
        DB::purge('mysql');
        $this->assertSame('moto_concurrency_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--database' => 'mysql', '--force' => true])->assertExitCode(0);
        $first = $this->manager();
        $second = $this->manager();
        $directory = storage_path('framework/testing/employee-race-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/start';
        $processes = [];
        try {
            foreach ([[$first, $second], [$second, $first]] as [$actor,$target]) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/employee-worker.php'), (string) $actor->id, (string) $target->id, $barrier], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'moto_concurrency_test', 'CACHE_STORE' => 'array', 'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1']);
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
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame([200, 403], $statuses);
            $this->assertSame(1, User::where('role', 'manager')->where('active', true)->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }File::deleteDirectory($directory);
            DB::disconnect('mysql');
        }
    }
}
