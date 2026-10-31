<?php

namespace Tests\Feature\Integration;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\ProductService;
use App\Services\Pos\CheckoutService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class ReportingSnapshotTest extends TestCase
{
    use MotoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('moto_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@tx_isolation AS isolation_level')->isolation_level);
        config(['database.connections.snapshot_writer' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('snapshot_writer');
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_pos_groups_remain_complete_when_another_checkout_consumes_the_last_unit(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(1);
        $cart = $this->cart($manager, [$variant->id => 1])->refresh();
        $wrote = false;
        DB::listen(function (QueryExecuted $event) use (&$wrote, $manager, $cart): void {
            if ($wrote || $event->connectionName !== 'mysql' || ! str_starts_with($event->sql, 'select * from `products`')) {
                return;
            }
            $wrote = true;
            DB::setDefaultConnection('snapshot_writer');
            try {
                app(CheckoutService::class)->complete($manager, $cart, $cart->version, 'pos-snapshot-last', ['method' => 'cash', 'received_centavos' => 10000]);
            } finally {
                DB::setDefaultConnection('mysql');
            }
        });
        $result = app(CatalogQuery::class)->forPos($manager, ['availability' => 'in']);
        $this->assertTrue($wrote);
        $this->assertSame(0, $variant->fresh()->stock);
        $this->assertSame(1, $result['variant_count']);
        $this->assertSame(1, $result['products']->first()->variants->count());
    }

    public function test_identifier_checks_use_current_rows_even_inside_an_older_read_snapshot(): void
    {
        $manager = $this->manager();
        $unit = Unit::create(['code' => 'pc', 'name' => 'Piece']);
        $rejected = false;
        DB::transaction(function () use ($manager, $unit, &$rejected): void {
            $this->assertSame(0, Variant::count());
            DB::setDefaultConnection('snapshot_writer');
            try {
                app(ProductService::class)->save($manager, null, ['name' => 'Committed competitor', 'variants' => [['sku' => 'SNAPSHOT-CODE', 'name' => 'Standard', 'unit_id' => $unit->id, 'price' => '100.00', 'threshold' => 3]]]);
            } finally {
                DB::setDefaultConnection('mysql');
            }
            try {
                app(ProductService::class)->save($manager, null, ['name' => 'Older reader', 'variants' => [['sku' => 'ANOTHER-CODE', 'barcode' => 'SNAPSHOT-CODE', 'name' => 'Standard', 'unit_id' => $unit->id, 'price' => '100.00', 'threshold' => 3]]]);
            } catch (ValidationException $error) {
                $rejected = true;
                $this->assertArrayHasKey('variants.0.barcode', $error->errors());
            }
        });
        $this->assertTrue($rejected, 'A stale snapshot must not hide a committed identifier.');
        $this->assertSame(1, Variant::count());
    }

    public function test_catalog_writes_recheck_a_revoked_manager_after_an_older_read_snapshot(): void
    {
        $manager = $this->manager();
        $unit = Unit::create(['code' => 'pc', 'name' => 'Piece']);
        $rejected = false;
        DB::transaction(function () use ($manager, $unit, &$rejected): void {
            $this->assertSame(1, User::where('active', true)->count());
            DB::connection('snapshot_writer')->table('users')->where('id', $manager->id)->update(['active' => false]);
            try {
                app(ProductService::class)->save($manager, null, ['name' => 'Revoked actor attempt', 'variants' => [['sku' => 'REVOKED-ACTOR', 'name' => 'Standard', 'unit_id' => $unit->id, 'price' => '100.00', 'threshold' => 3]]]);
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
                $rejected = true;
            }
        });
        $this->assertTrue($rejected, 'Current privileges must be checked before catalog writes.');
        $this->assertSame(0, Product::count());
    }

    public static function pages(): array
    {
        return [['/reports'], ['/dashboard'], ['/sales']];
    }

    #[DataProvider('pages')]
    public function test_page_totals_share_one_snapshot_while_another_connection_completes_a_sale(string $page): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(4, 0));
        $manager = $this->manager();
        $firstVariant = $this->variant(4);
        $secondVariant = $this->variant(4, 20000);
        $firstCart = $this->cart($manager, [$firstVariant->id => 1])->refresh();
        $secondCart = $this->cart($manager, [$secondVariant->id => 1])->refresh();
        app(CheckoutService::class)->complete($manager, $firstCart, $firstCart->version, 'snapshot-first', ['method' => 'cash', 'received_centavos' => 10000]);
        $wrote = false;
        DB::listen(function (QueryExecuted $event) use (&$wrote, $manager, $secondCart): void {
            if ($wrote || $event->connectionName !== 'mysql' || ! str_contains(strtolower($event->sql), 'sum(') || ! str_contains($event->sql, 'sales')) {
                return;
            }
            $wrote = true;
            DB::setDefaultConnection('snapshot_writer');
            try {
                app(CheckoutService::class)->complete($manager, $secondCart, $secondCart->version, 'snapshot-second', ['method' => 'cash', 'received_centavos' => 20000]);
            } finally {
                DB::setDefaultConnection('mysql');
            }
        });
        $this->actingAs($manager)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $response = $this->get($page)->assertOk();
        $this->assertTrue($wrote, 'The independent writer must commit during page queries.');
        $this->assertSame(2, Sale::count());
        if ($page === '/reports') {
            $data = $response->viewData('data');
            $this->assertSame(1, $data['summary']['completed_count']);
            $this->assertSame(10000, $data['summary']['recorded_sales']);
            $this->assertSame(1, array_sum(array_column($data['payments'], 'completed_count')));
        } elseif ($page === '/dashboard') {
            $data = $response->viewData('data');
            $this->assertSame(1, $data['completed_count']);
            $this->assertSame(1, $data['recent_sales']->count());
            $this->assertSame(10000, array_sum(array_column($data['hourly_sales'], 'sales_centavos')));
        } else {
            $this->assertSame(1, $response->viewData('sales')->total());
            $this->assertSame(1, $response->viewData('summary')['count']);
            $this->assertSame(10000, $response->viewData('summary')['recorded']);
        }
    }
}
