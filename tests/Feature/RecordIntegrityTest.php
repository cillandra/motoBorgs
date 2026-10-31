<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Pos\CheckoutService;
use App\Services\Sales\RefundService;
use App\Services\Sales\ReturnService;
use App\Services\System\RecordIntegrity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class RecordIntegrityTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_healthy_records_reconcile_and_command_is_read_only(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(5);
        $cart = $this->cart($manager, [$variant->id => 2])->refresh();
        app(CheckoutService::class)->complete($manager, $cart, $cart->version, 'audit-clean', ['method' => 'cash', 'received_centavos' => 20000]);
        $before = DB::table('audit_events')->count();
        $result = app(RecordIntegrity::class)->inspect();
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['summary']['sales']);
        $this->assertSame(0, array_sum($result['checks']));
        $this->artisan('moto:audit-data', ['--json' => true])->assertExitCode(0);
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_corrupt_stock_money_cart_and_refund_records_are_detected_without_repair(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(5);
        $cart = $this->cart($manager, [$variant->id => 2])->refresh();
        $sale = app(CheckoutService::class)->complete($manager, $cart, $cart->version, 'audit-corrupt', ['method' => 'cash', 'received_centavos' => 20000])->sale;
        $line = $sale->lines->first();
        $return = app(ReturnService::class)->request($manager, $sale, [$line->id => 1], 'Returned sample');
        $return = app(ReturnService::class)->review($manager, $return, [$return->lines->first()->id => ['restock_quantity' => 1]], true, 'Sample inspected');
        $refund = app(RefundService::class)->recordOutcome($manager, $return, 'audit-refund', 10000, 'paid', 'AUDIT-SAMPLE');
        $variant->refresh()->increment('stock');
        $line->increment('total_centavos');
        $refund->update(['amount_centavos' => 20001]);
        $cart->refresh()->update(['status' => 'draft']);
        $result = app(RecordIntegrity::class)->inspect();
        $this->assertFalse($result['ok']);
        foreach (['stock_balance', 'movement_balance', 'sale_line_amount', 'sale_line_summary', 'cart_state', 'refund_limit', 'sale_refund_limit'] as $check) {
            $this->assertGreaterThan(0, $result['checks'][$check], $check);
        }
        $this->artisan('moto:audit-data', ['--json' => true])->assertExitCode(1);
        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(20001, $refund->fresh()->amount_centavos);
    }

    public function test_default_seeding_does_not_add_an_unrelated_test_user(): void
    {
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Default seeding should be restricted to moto_demo.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        }
        $this->assertSame(0, User::count());
    }
}
