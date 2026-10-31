<?php

namespace Tests\Feature\Integration;

use App\Models\Unit;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class CatalogIdentifierRaceTest extends TestCase
{
    use MotoFixtures;

    public function test_two_managers_cannot_allocate_one_code_to_different_variants(): void
    {
        config(['database.connections.mysql.database' => 'moto_concurrency_test']);
        DB::purge('mysql');
        $this->assertSame('moto_concurrency_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--database' => 'mysql', '--force' => true])->assertExitCode(0);
        $actors = [$this->manager(), $this->manager()];
        $unit = Unit::create(['code' => 'pc', 'name' => 'Piece']);
        $directory = storage_path('framework/testing/catalog-race-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/start';
        $workers = [];
        try {
            foreach ($actors as $index => $actor) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/catalog-worker.php'), (string) $actor->id, (string) $unit->id, $index === 0 ? 'sku' : 'barcode', $barrier], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'moto_concurrency_test', 'CACHE_STORE' => 'array', 'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1']);
                $worker->setTimeout(25);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'.ready.*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'.ready.*'));
            file_put_contents($barrier.'.go', 'go');
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $statuses[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame([200, 422], $statuses);
            $this->assertSame(1, Variant::count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            File::deleteDirectory($directory);
            DB::disconnect('mysql');
        }
    }
}
