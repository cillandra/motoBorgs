<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Services\Pos\ApprovalService;
use App\Services\Pos\CheckoutService;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\DashboardQuery;
use App\Services\Reporting\ReportFilter;
use App\Services\Reporting\ReportQuery;
use App\Services\Sales\RefundService;
use App\Services\Sales\ReturnService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function signIn(User $user): static
    {
        return $this->actingAs($user)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
    }

    private function example(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(5, 0));
        $manager = $this->manager();
        $staff = $this->staff();
        $a = $this->variant(3, 100);
        $b = $this->variant(3, 100);
        $a->product->update(['category_id' => Category::create(['name' => 'Original brakes'])->id]);
        $b->product->update(['category_id' => Category::create(['name' => 'Original engine'])->id]);
        $cart = $this->cart($staff, [$a->id => 1, $b->id => 1])->refresh();
        app(ApprovalService::class)->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 1], 'Rounding fixture');
        $sale = app(CheckoutService::class)->complete($staff, $cart, 2, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => 200])->sale;
        $returns = app(ReturnService::class);
        $return = $returns->request($staff, $sale, $sale->lines->mapWithKeys(fn ($line) => [$line->id => 1])->all(), 'Both units returned');
        $returns->review($manager, $return, $return->lines->mapWithKeys(fn ($line) => [$line->id => ['restock_quantity' => 0]])->all(), true, 'Damaged units inspected');

        return compact('manager', 'staff', 'a', 'b', 'sale', 'return');
    }

    public function test_gross_discount_paid_refunds_and_return_units_reconcile_without_pending_money(): void
    {
        $e = $this->example();
        $filter = ReportFilter::from(['start' => '2026-10-26', 'end' => '2026-10-26']);
        $query = app(ReportQuery::class);
        app(RefundService::class)->recordOutcome($e['manager'], $e['return'], 'pending', 100, 'pending', 'TRACK-P');
        $summary = $query->summary($filter);
        $this->assertSame([200, 1, 0, 199, 1, 2, 2, 0, 1], [$summary['gross_sales'], $summary['discounts'], $summary['paid_refunds'], $summary['net_sales'], $summary['completed_count'], $summary['sold_units'], $summary['returned_units'], $summary['net_units'], $summary['return_count']]);
        app(RefundService::class)->recordOutcome($e['manager'], $e['return'], 'pending', 100, 'paid', 'TRACK-P');
        $summary = $query->summary($filter);
        $this->assertSame(99, $summary['net_sales']);
        $this->assertSame(100, array_sum(array_column($query->payments($filter), 'paid_refunds')));
        $this->assertSame(99, array_sum(array_column($query->products($filter), 'net_sales')));
        $dashboard = app(DashboardQuery::class)->for($e['staff'], '2026-10-26');
        $this->assertSame([$summary['net_sales'], $summary['net_units']], [$dashboard['net_sales'], $dashboard['net_units']]);
    }

    public function test_partial_payouts_across_dates_attribute_every_cent_to_original_lines_once(): void
    {
        $e = $this->example();
        $service = app(RefundService::class);
        $service->recordOutcome($e['manager'], $e['return'], 'one', 100, 'paid', 'TRACK-1');
        $this->travelTo(now()->addDay());
        $service->recordOutcome($e['manager'], $e['return'], 'two', 99, 'paid', 'TRACK-2');
        $query = app(ReportQuery::class);
        $first = $query->products(ReportFilter::from(['start' => '2026-10-26', 'end' => '2026-10-26']));
        $second = $query->products(ReportFilter::from(['start' => '2026-10-27', 'end' => '2026-10-27']));
        $this->assertSame([50, 50], array_column($first, 'paid_refunds'));
        $this->assertSame([49, 50], array_column($second, 'paid_refunds'));
        $this->assertSame(-99, $query->summary(ReportFilter::from(['start' => '2026-10-27', 'end' => '2026-10-27']))['net_sales']);
        $this->assertSame(0, $query->summary(ReportFilter::from(['start' => '2026-10-26', 'end' => '2026-10-27']))['net_sales']);
        $this->assertSame(0, $query->summary(ReportFilter::from(['start' => '2026-10-27', 'end' => '2026-10-27']))['returned_units']);
    }

    public function test_product_category_filters_use_sale_snapshots_on_sales_returns_and_payouts(): void
    {
        $e = $this->example();
        app(RefundService::class)->recordOutcome($e['manager'], $e['return'], 'all', 199, 'paid', 'TRACK-A');
        $line = $e['sale']->lines->first();
        $original = $line->category_name;
        $e['a']->product->category->update(['name' => 'Renamed category']);
        $e['a']->product->update(['name' => 'Renamed product', 'archived_at' => now()]);
        $e['a']->update(['archived_at' => now()]);
        $e['staff']->update(['active' => false, 'name' => 'Renamed cashier']);
        $filter = ReportFilter::from(['start' => '2026-10-26', 'end' => '2026-10-26', 'variant_id' => $e['a']->id, 'category_id' => $line->category_id_snapshot]);
        $query = app(ReportQuery::class);
        $summary = $query->summary($filter);
        $this->assertSame([100, 1, 99, 0, 1, 1], [$summary['gross_sales'], $summary['discounts'], $summary['paid_refunds'], $summary['net_sales'], $summary['sold_units'], $summary['returned_units']]);
        $this->assertSame($original, $query->products($filter)[0]['category_name']);
        $wrong = ReportFilter::from(['start' => '2026-10-26', 'end' => '2026-10-26', 'variant_id' => $e['a']->id, 'category_id' => $e['b']->product->category_id]);
        $this->assertSame(0, $query->summary($wrong)['completed_count']);
    }

    public function test_report_date_bounds_and_validation_are_explicit(): void
    {
        $filter = ReportFilter::from(['start' => '2026-10-26', 'end' => '2026-10-26']);
        $this->assertSame('2026-10-25 16:00:00', $filter->startUtc->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-26 16:00:00', $filter->endUtc->format('Y-m-d H:i:s'));
        $this->expectException(ValidationException::class);
        ReportFilter::from(['start' => '2026-10-27', 'end' => '2026-10-26']);
    }

    public function test_staff_cannot_view_export_or_print_reports(): void
    {
        $this->signIn($this->staff());
        foreach (['/reports', '/reports/export', '/reports/print'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_csv_quotes_and_neutralizes_formula_prefixes_even_after_control_whitespace(): void
    {
        $values = ['=1+1', '+cmd', '-cmd', '@sum', "\t=bad", "\r  +bad", 'name, with "quotes"', -123];
        $response = app(CsvExporter::class)->stream('test.csv', ['Value'], array_map(fn ($v) => [$v], $values));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream, escape: '');
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row[0];
        } fclose($stream);
        foreach (array_slice($values, 0, 6) as $i => $value) {
            $this->assertSame("'".$value, $rows[$i]);
        }
        $this->assertSame($values[6], $rows[6]);
        $this->assertSame('-123', $rows[7]);
    }

    public function test_query_failures_preserve_report_and_export_retry_filters(): void
    {
        $manager = $this->manager();
        $this->mock(ReportQuery::class, fn ($mock) => $mock->shouldReceive('summary')->times(2)->andThrow(new QueryException('mysql', 'select', [], new \Exception('unavailable'))));
        $this->signIn($manager)->get('/reports?start=2026-10-26&end=2026-10-26&variant_id=7')->assertStatus(503)->assertSee('Data unavailable')->assertSee('variant_id=7', false)->assertDontSee('₱0.00');
        $this->get('/reports/export?start=2026-10-26&end=2026-10-26&variant_id=7&type=summary')->assertStatus(503)->assertSee('Retry CSV')->assertSee('variant_id=7', false);
    }

    public function test_screen_json_csv_and_print_use_identical_filtered_measures(): void
    {
        $e = $this->example();
        app(RefundService::class)->recordOutcome($e['manager'], $e['return'], 'all', 199, 'paid', 'TRACK-A');
        $filters = ['start' => '2026-10-26', 'end' => '2026-10-26', 'variant_id' => $e['a']->id];
        $query = http_build_query($filters);
        $this->signIn($e['manager']);
        $json = $this->getJson('/reports?'.$query)->assertOk()->json();
        $this->assertSame(0, $json['summary']['net_sales']);
        $this->assertSame($json['summary']['net_sales'], array_sum(array_column($json['daily'], 'net_sales')));
        $this->assertSame($json['summary']['net_units'], array_sum(array_column($json['daily'], 'net_units')));
        $this->get('/reports?'.$query)->assertOk()->assertSee('Net sales')->assertSee('₱0.00');
        $csv = $this->get('/reports/export?'.$query.'&type=summary')->assertOk()->streamedContent();
        $this->assertStringContainsString('net_sales,0', $csv);
        $this->get('/reports/print?'.$query)->assertOk()->assertSee('2026-10-26')->assertSee('₱0.00')->assertSee('Paid refunds');
    }
}
