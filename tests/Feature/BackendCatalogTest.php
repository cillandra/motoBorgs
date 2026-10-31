<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Variant;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class BackendCatalogTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_pos_pagination_keeps_all_matching_variants_of_a_product_together_and_hides_costs(): void
    {
        $staff = $this->staff();
        $first = $this->variant(4, 20000);
        $first->product->update(['name' => 'A multi-variant product']);
        for ($index = 0; $index < 27; $index++) {
            Variant::factory()->create(['product_id' => $first->product_id, 'unit_id' => $first->unit_id, 'cost_centavos' => 12345]);
        }
        $other = $this->variant(5, 30000);
        $other->product->update(['name' => 'Z other product']);
        $result = app(CatalogQuery::class)->forPos($staff, [], 1);
        $this->assertSame(29, $result['variant_count']);
        $this->assertSame(2, $result['products']->total());
        $this->assertSame(28, $result['products']->first()->variants->count());
        foreach ($result['products']->first()->variants as $variant) {
            $this->assertArrayNotHasKey('cost_centavos', $variant->toArray());
        }
        $filtered = app(CatalogQuery::class)->forPos($staff, ['q' => $first->sku], 1);
        $this->assertSame(1, $filtered['variant_count']);
        $this->assertSame([$first->id], $filtered['products']->first()->variants->pluck('id')->all());
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/pos')->assertOk()->assertSee('28 variants')->assertSee('29 matching variants');
    }

    public function test_legacy_ambiguous_scan_fails_instead_of_choosing_a_wrong_product(): void
    {
        $manager = $this->manager();
        $one = $this->variant(3);
        $two = $this->variant(3);
        $two->update(['barcode' => $one->sku]);
        $this->actingAs($manager)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->getJson('/pos/lookup?code='.urlencode($one->sku))->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_service_rejects_cross_column_and_batch_identifier_collisions_without_partial_saves(): void
    {
        $manager = $this->manager();
        $existing = $this->variant(5);
        $payload = ['name' => 'New sample', 'variants' => [['sku' => 'NEW-SKU', 'barcode' => $existing->sku, 'name' => 'Standard', 'unit_id' => $existing->unit_id, 'price' => '123.45', 'threshold' => 3]]];
        $before = Product::count();
        try {
            app(ProductService::class)->save($manager, null, $payload);
            $this->fail('An ambiguous identifier was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('variants.0.barcode', $error->errors());
        }
        $this->assertSame($before, Product::count());
        $payload['variants'][0]['barcode'] = 'BATCH-CODE';
        $payload['variants'][] = ['sku' => 'batch-code', 'name' => 'Second', 'unit_id' => $existing->unit_id, 'price' => '234.56', 'threshold' => 3];
        try {
            app(ProductService::class)->save($manager, null, $payload);
            $this->fail('A sibling barcode/SKU collision was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('variants.1.sku', $error->errors());
        }
        $this->assertSame($before, Product::count());
    }

    public function test_duplicate_database_identifier_is_returned_as_validation_not_a_server_error(): void
    {
        $manager = $this->manager();
        $existing = $this->variant(5);
        $this->expectException(ValidationException::class);
        app(ProductService::class)->save($manager, null, ['name' => 'Duplicate sample', 'variants' => [['sku' => $existing->sku, 'name' => 'Standard', 'unit_id' => $existing->unit_id, 'price' => '12.34', 'threshold' => 3]]]);
    }
}
