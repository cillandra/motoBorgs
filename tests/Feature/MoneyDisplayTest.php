<?php

namespace Tests\Feature;

use App\Services\Pos\CheckoutService;
use App\Services\Sales\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class MoneyDisplayTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_amounts_are_grouped_on_screens_but_edit_fields_keep_parseable_values(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(5, 180000);
        $this->actingAs($manager)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp])->get('/inventory')->assertOk()->assertSee('₱1,800.00');
        $this->get('/products/'.$variant->product_id.'/edit')->assertOk()->assertSee('value="1800.00"', false)->assertDontSee('value="1,800.00"', false);
        $cart = $this->cart($manager, [$variant->id => 2])->refresh();
        $sale = app(CheckoutService::class)->complete($manager, $cart, $cart->version, 'grouped-money-example', ['method' => 'cash', 'received_centavos' => 400000])->sale;
        $this->get('/sales/'.$sale->id)->assertOk()->assertSee('₱3,600.00')->assertSee('₱4,000.00')->assertSee('₱400.00');
        $this->get('/sales/'.$sale->id.'/receipt?copy=1')->assertOk()->assertSee('₱3,600.00');
        $line = $sale->lines->first();
        $return = app(ReturnService::class)->request($manager, $sale, [$line->id => 1], 'Inspect sample fitment');
        app(ReturnService::class)->review($manager, $return, [$return->lines->first()->id => ['restock_quantity' => 1]], true, 'Sellable item inspected');
        $this->get('/returns/'.$return->id)->assertOk()->assertSee('value="1800.00"', false)->assertDontSee('value="1,800.00"', false);
        $this->get('/reports?start='.now('Asia/Manila')->toDateString().'&end='.now('Asia/Manila')->toDateString())->assertOk()->assertSee('₱3,600.00');
    }
}
