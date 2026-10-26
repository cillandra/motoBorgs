<?php

namespace Tests\Feature;

use App\Services\Pos\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class ModalFlowTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_inventory_actions_offer_accessible_modal_entry_points_and_full_page_fallbacks(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->manager())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/inventory')->assertOk()->assertSee('data-modal-link', false)->assertSee('id="moto-workspace-modal"', false)->assertSee('Discard unsaved changes?');
        $this->get('/products/create')->assertOk()->assertSee('data-product-form', false);
        $this->get('/products/'.$variant->product_id.'/edit')->assertOk()->assertSee('data-product-form', false);
        $this->get('/inventory/'.$variant->id)->assertOk()->assertSee('data-modal-link', false)->assertSee('Receive stock');
        $this->get('/inventory/'.$variant->id.'/stock')->assertOk()->assertSee('data-stock-form', false);
    }

    public function test_modal_navigation_does_not_expand_staff_write_permissions(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->staff())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/inventory')->assertOk()->assertSee('data-modal-link', false)->assertDontSee('Add product');
        $this->get('/inventory/'.$variant->id)->assertOk()->assertDontSee('Receive stock')->assertDontSee('Edit product');
        $this->get('/products/create')->assertForbidden();
        $this->get('/inventory/'.$variant->id.'/stock')->assertForbidden();
    }

    public function test_receipt_copy_navigation_supports_a_modal_and_retains_staff_ownership(): void
    {
        $staff = $this->staff();
        $variant = $this->variant();
        $cart = $this->cart($staff, [$variant->id => 1])->refresh();
        $sale = app(CheckoutService::class)->complete($staff, $cart, 1, 'modal-receipt', ['method' => 'cash', 'received_centavos' => 10000])->sale;
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/sales/'.$sale->id.'/receipt')->assertOk()->assertSee('data-modal-link', false)->assertSee('COPY');
        $this->actingAs($this->staff())->get('/sales/'.$sale->id.'/receipt')->assertForbidden();
    }

    public function test_product_form_accepts_blank_optional_barcodes_and_keeps_sku_validation(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->manager())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $payload = ['name' => 'Demonstration product', 'variants' => [
            ['sku' => 'MODAL-NEW-01', 'barcode' => '', 'name' => 'Small', 'unit_id' => $variant->unit_id, 'price' => '100.00', 'threshold' => 3],
            ['sku' => 'MODAL-NEW-02', 'barcode' => '', 'name' => 'Large', 'unit_id' => $variant->unit_id, 'price' => '120.00', 'threshold' => 3],
        ]];
        $this->postJson('/products', $payload)->assertCreated();
        $this->assertDatabaseHas('variants', ['sku' => 'MODAL-NEW-01', 'barcode' => null]);
        $this->assertDatabaseHas('variants', ['sku' => 'MODAL-NEW-02', 'barcode' => null]);
        $this->postJson('/products', $payload)->assertUnprocessable()->assertJsonValidationErrors(['variants.0.sku']);
    }
}
