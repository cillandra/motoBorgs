<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Services\Pos\CheckoutService;
use App\Services\Reporting\ReportingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class ReportingContextTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function recorded(User $cashier, string $at): Sale
    {
        $variant = $this->variant(5);
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $sale = app(CheckoutService::class)->complete($cashier, $cart, $cart->version, 'context-'.$cashier->id.'-'.$at, ['method' => 'cash', 'received_centavos' => 10000])->sale;
        $sale->forceFill(['created_at' => $at])->save();

        return $sale->refresh();
    }

    public function test_visible_latest_dates_follow_role_and_snapshot_filters_without_backdating_today(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(4, 0));
        $manager = $this->manager();
        $staff = $this->staff();
        $older = $this->recorded($staff, '2026-10-28 02:00:00');
        $this->recorded($manager, '2026-10-31 02:00:00');
        $context = app(ReportingContext::class);
        $today = ['start' => '2026-11-01', 'end' => '2026-11-01'];
        $this->assertSame('2026-10-31', $context->for($manager, $today)['latest_date']);
        $this->assertSame('2026-10-28', $context->for($staff, $today)['latest_date']);
        $filtered = $context->for($manager, $today + ['variant_id' => $older->lines->first()->variant_id]);
        $this->assertSame('2026-10-28', $filtered['latest_date']);
        $this->assertTrue($filtered['is_today']);
        $this->assertSame('2026-11-01', $filtered['start']);
        $this->actingAs($manager)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/dashboard')->assertOk()->assertSee('Showing today')->assertSee('2026-10-31')->assertViewHas('data', fn ($data) => $data['completed_count'] === 0);
        $this->get('/reports')->assertOk()->assertSee('Showing today')->assertSee('View latest sales date');
        $this->get('/sales')->assertOk()->assertSee('Showing all dates');
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/dashboard')->assertOk()->assertSee('2026-10-28')->assertDontSee('2026-10-31');
        $this->get('/dashboard?date=2026-10-28')->assertOk()->assertSee('Your work on selected date')->assertSee('My sales on selected date');
    }

    public function test_an_empty_account_has_no_latest_date_or_fake_history(): void
    {
        $context = app(ReportingContext::class)->for($this->staff(), []);
        $this->assertNull($context['latest_date']);
        $this->assertFalse($context['has_sales']);
        $this->assertSame('Showing all dates', $context['label']);
    }
}
