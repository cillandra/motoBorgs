<?php

namespace Tests\Feature\Integration;

use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\Pos\CheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class CheckoutRaceTest extends TestCase
{
    use MotoFixtures;

    public function test_two_cashiers_cannot_both_buy_last_unit(): void
    {
        $this->assertTrue(method_exists(CheckoutService::class, 'complete'), 'Atomic checkout is not implemented.');
        config(['database.connections.mysql.database' => 'moto_concurrency_test']);
        DB::purge('mysql');
        $this->assertSame('moto_concurrency_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--database' => 'mysql', '--force' => true])->assertExitCode(0);
        $first = $this->staff();
        $second = $this->staff();
        $variant = $this->variant(1);
        $firstCart = $this->cart($first, [$variant->id => 1])->refresh();
        $secondCart = $this->cart($second, [$variant->id => 1])->refresh();
        $directory = storage_path('framework/testing/race-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $barrier = $directory.'/start';
        $processes = [];
        try {
            foreach ([[$first, $firstCart], [$second, $secondCart]] as [$cashier, $cart]) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/checkout-worker.php'), (string) $cashier->id, (string) $cart->id, $barrier, (string) Str::uuid()], base_path(),
                    ['APP_ENV' => 'testing', 'DB_DATABASE' => 'moto_concurrency_test', 'CACHE_STORE' => 'array', 'ARGON_MEMORY' => '1024', 'ARGON_TIME' => '1']);
                $process->setTimeout(25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'.ready.*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'.ready.*'), 'Both independent connections must reach the synchronized start.');
            file_put_contents($barrier.'.go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame([201, 409], $statuses);
            $loser = collect($results)->firstWhere('status', 409);
            $this->assertStringContainsString('stock', strtolower($loser['message']));
            $this->assertSame(0, $variant->fresh()->stock);
            $this->assertSame(1, Sale::count());
            $this->assertSame(1, StockMovement::where('type', 'sale')->count());
            $this->assertSame(0, (int) $variant->movements()->sum('delta'));
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
