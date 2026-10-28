<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\RefundRecord;
use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Variant;
use App\Services\Pos\CheckoutService;
use App\Services\Reporting\DashboardQuery;
use Database\Seeders\DemoActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class DemoActivitySeederTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_sample_activity_is_additive_consistent_and_repeat_safe(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $manager = $this->manager();
        $staff = $this->staff();
        $password = $manager->password;
        $original = $this->variant(14);
        $draft = $this->cart($manager, [$original->id => 1])->refresh();
        $this->seed(DemoActivitySeeder::class);

        $this->assertGreaterThan(100, Sale::count());
        $this->assertSame(12, app(DashboardQuery::class)->for($manager, '2026-10-28')['completed_count']);
        $this->assertSame(5, DB::table('payment_records')->distinct()->count('method'));
        $this->assertSame(30, Sale::selectRaw('DATE(created_at) as day')->distinct()->get()->count());
        $this->assertGreaterThan(0, Sale::sum('discount_centavos'));
        $this->assertTrue(ReturnRequest::where('status', 'pending')->exists());
        $this->assertTrue(ReturnRequest::where('status', 'rejected')->exists());
        foreach (['paid', 'pending', 'failed'] as $status) {
            $this->assertTrue(RefundRecord::where('status', $status)->exists());
        }
        $this->assertTrue(Cart::where('cashier_id', $staff->id)->where('status', 'held')->exists());
        foreach (Variant::all() as $variant) {
            $this->assertSame($variant->stock, (int) $variant->movements()->sum('delta'));
            $this->assertSame($variant->stock, $variant->movements()->latest('id')->first()->balance_after);
        }
        $this->assertSame(0, DB::table('sales as s')->whereRaw('s.total_centavos != (select sum(l.total_centavos) from sale_lines l where l.sale_id=s.id)')->count());
        $this->assertSame(0, DB::table('payment_records as p')->join('sales as s', 's.id', '=', 'p.sale_id')->whereColumn('p.amount_centavos', '!=', 's.total_centavos')->count());
        $counts = [Sale::count(), Cart::count(), DB::table('stock_movements')->count()];
        $this->seed(DemoActivitySeeder::class);
        $this->assertSame($counts, [Sale::count(), Cart::count(), DB::table('stock_movements')->count()]);
        $this->assertSame(14, $original->fresh()->stock);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(1, $draft->lines()->first()->quantity);
        $this->assertSame($password, $manager->fresh()->password);
        $this->assertSame('2026-10-28 12:00', now()->format('Y-m-d H:i'));
    }

    public function test_seeder_refuses_other_database_names_before_writing(): void
    {
        $connection = DB::connection();
        $originalName = $connection->getDatabaseName();
        $connection->setDatabaseName('unapproved_database');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('restricted');
            $this->seed(DemoActivitySeeder::class);
        } finally {
            $connection->setDatabaseName($originalName);
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_failure_rolls_back_the_batch_and_restores_the_clock(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->manager();
        $this->staff();
        $this->mock(CheckoutService::class)->shouldReceive('complete')->once()->andThrow(new RuntimeException('Simulated checkout failure'));
        try {
            $this->seed(DemoActivitySeeder::class);
            $this->fail('Expected the sample batch to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated checkout failure', $error->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('carts', 0);
        $this->assertFalse(Setting::where('section', 'demo_activity')->exists());
        $this->assertSame('2026-10-28 12:00', now()->format('Y-m-d H:i'));
    }
}
