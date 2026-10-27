<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Services\Pos\CheckoutService;
use App\Services\Reporting\DashboardQuery;
use App\Services\Reporting\SalesQuery;
use App\Services\Sales\RefundService;
use App\Services\Sales\ReturnService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function signedIn(User $user): static
    {
        return $this->actingAs($user)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
    }

    private function recorded(User $cashier, int $quantity = 1, int $price = 10000, ?string $at = null): Sale
    {
        $variant = $this->variant(10, $price);
        $cart = $this->cart($cashier, [$variant->id => $quantity])->refresh();
        $sale = app(CheckoutService::class)->complete($cashier, $cart, 1, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => $quantity * $price])->sale;
        $sale->forceFill(['created_at' => $at ?? '2026-10-25 16:00:00'])->save();

        return $sale;
    }

    public function test_rows_and_dashboard_totals_scope_every_measure_to_the_cashier(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $manager = $this->manager();
        $own = $this->recorded($staff, 2);
        $this->recorded($other, 1, 5000);
        $query = app(SalesQuery::class);
        $this->assertSame([$own->id], $query->search($staff, ['cashier_id' => $other->id])->pluck('id')->all());
        $this->assertSame(2, $query->search($manager, [])->total());
        $mine = app(DashboardQuery::class)->for($staff, '2026-10-26');
        $all = app(DashboardQuery::class)->for($manager, '2026-10-26');
        $this->assertSame([20000, 1, 2], [$mine['net_sales'], $mine['completed_count'], $mine['net_units']]);
        $this->assertSame([25000, 2, 3], [$all['net_sales'], $all['completed_count'], $all['net_units']]);
        $this->assertSame(20000, array_sum(array_column($mine['hourly_sales'], 'sales_centavos')));
    }

    public function test_return_units_use_acceptance_date_and_only_paid_money_uses_payout_date(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(5, 0));
        $staff = $this->staff();
        $manager = $this->manager();
        $sale = $this->recorded($staff, 2, 10000, '2026-10-24 16:00:00');
        $line = $sale->lines->first();
        $service = app(ReturnService::class);
        $return = $service->request($staff, $sale, [$line->id => 1], 'Wrong fitment');
        $service->review($manager, $return, [$return->lines->first()->id => ['restock_quantity' => 1]], true, 'Sellable unit inspected');
        $key = (string) Str::uuid();
        app(RefundService::class)->recordOutcome($manager, $return, $key, 10000, 'pending', 'TRACK-1');
        $pending = app(DashboardQuery::class)->for($staff, '2026-10-26');
        $this->assertSame([0, 0, -1], [$pending['net_sales'], $pending['completed_count'], $pending['net_units']]);
        app(RefundService::class)->recordOutcome($manager, $return, $key, 10000, 'paid', 'TRACK-1');
        $paid = app(DashboardQuery::class)->for($staff, '2026-10-26');
        $this->assertSame(-10000, $paid['net_sales']);
        $this->assertSame([$sale->id], app(SalesQuery::class)->search($staff, ['status' => 'partial'])->pluck('id')->all());
        $this->assertSame(0, app(SalesQuery::class)->search($staff, ['status' => 'refunded'])->total());
        $previous = app(DashboardQuery::class)->for($staff, '2026-10-25');
        $this->assertSame([20000, 1, 2], [$previous['net_sales'], $previous['completed_count'], $previous['net_units']]);
        $this->travelBack();
    }

    public function test_inclusive_manila_dates_use_half_open_utc_bounds(): void
    {
        $staff = $this->staff();
        $before = $this->recorded($staff, 1, 100, '2026-10-25 15:59:59');
        $first = $this->recorded($staff, 1, 200, '2026-10-25 16:00:00');
        $last = $this->recorded($staff, 1, 300, '2026-10-26 15:59:59');
        $after = $this->recorded($staff, 1, 400, '2026-10-26 16:00:00');
        $result = app(SalesQuery::class)->search($staff, ['start' => '2026-10-26', 'end' => '2026-10-26']);
        $this->assertSame([$last->id, $first->id], $result->pluck('id')->all());
        $day = app(DashboardQuery::class)->for($staff, '2026-10-26');
        $this->assertSame(500, $day['net_sales']);
        $this->signedIn($staff)->get('/sales?end=2026-10-26')->assertOk();
        $this->get('/sales?start=2026-10-27&end=2026-10-26')->assertSessionHasErrors('end');
    }

    public function test_combined_search_status_method_cashier_and_pagination_remain_server_scoped(): void
    {
        $staff = $this->staff();
        $manager = $this->manager();
        $other = $this->staff();
        $a = $this->recorded($staff);
        $b = $this->recorded($staff);
        $this->recorded($other);
        $filters = ['q' => $a->lines->first()->sku, 'start' => '2026-10-26', 'end' => '2026-10-26', 'method' => 'cash', 'status' => 'completed', 'cashier_id' => $staff->id];
        $this->assertSame([$a->id], app(SalesQuery::class)->search($manager, $filters)->pluck('id')->all());
        $page = app(SalesQuery::class)->search($manager, ['cashier_id' => $staff->id], 1);
        $this->assertSame(2, $page->total());
        $this->assertSame($b->id, $page->first()->id);
        $this->signedIn($manager)->get('/sales?'.http_build_query($filters))->assertOk()->assertSee('data-modal-link', false)->assertSee($a->lines->first()->sku);
    }

    public function test_archived_products_and_inactive_renamed_cashiers_retain_sale_snapshots(): void
    {
        $staff = $this->staff();
        $manager = $this->manager();
        $sale = $this->recorded($staff);
        $name = $sale->cashier_name;
        $line = $sale->lines->first();
        $original = $line->product_name;
        $staff->update(['name' => 'New account name', 'active' => false]);
        $line->variant->product->update(['name' => 'Renamed catalog product', 'archived_at' => now()]);
        $line->variant->update(['archived_at' => now()]);
        $this->signedIn($manager)->get('/sales/'.$sale->id)->assertOk()->assertSee($name)->assertSee($original)->assertDontSee('Renamed catalog product');
        $this->assertSame($sale->id, app(SalesQuery::class)->search($manager, ['q' => $name])->first()->id);
    }

    public function test_no_sales_day_keeps_current_stock_attention_and_staff_cannot_open_another_sale(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $variant = $this->variant(0);
        $sale = $this->recorded($other);
        $day = app(DashboardQuery::class)->for($staff, '2026-10-27');
        $this->assertSame(0, $day['net_sales']);
        $this->assertGreaterThan(0, $day['stock_alerts']['count']);
        $this->signedIn($staff)->get('/sales/'.$sale->id)->assertForbidden();
        $this->get('/sales/'.$sale->id.'/receipt')->assertForbidden();
        $this->get('/sales')->assertOk()->assertDontSee($sale->cashier_name);
    }

    public function test_unavailable_dashboard_is_explicit_and_preserves_selected_date(): void
    {
        $manager = $this->manager();
        $this->mock(DashboardQuery::class, fn ($mock) => $mock->shouldReceive('for')->once()->andThrow(new QueryException('mysql', 'select', [], new \Exception('Database unreachable'))));
        $this->signedIn($manager)->get('/dashboard?date=2026-10-24')->assertStatus(503)->assertSee('Data unavailable')->assertSee('2026-10-24')->assertDontSee('₱0.00');
    }
}
