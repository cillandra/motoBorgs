<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function signIn(User $user): void
    {
        $this->actingAs($user)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
    }

    private function payload(string $sku = 'NEW-001'): array
    {
        $unit = Unit::firstOrCreate(['code' => 'pc'], ['name' => 'Piece']);

        return ['name' => 'New brake pad', 'brand' => 'Demo', 'compatibility' => 'Verify fitment', 'variants' => [['sku' => $sku, 'name' => 'Standard', 'unit_id' => $unit->id, 'price' => '123.45', 'threshold' => 3]]];
    }

    public function test_staff_filters_combine_and_never_return_costs_or_archived_items(): void
    {
        $staff = $this->staff();
        $category = Category::factory()->create(['name' => 'Brakes']);
        $wanted = $this->variant(2);
        $wanted->update(['cost_centavos' => 77777]);
        $wanted->product->update(['name' => 'Brake pad', 'category_id' => $category->id]);
        $other = $this->variant(10);
        $other->product->update(['name' => 'Brake spare', 'category_id' => $category->id]);
        $archived = $this->variant(1);
        $archived->product->update(['name' => 'Brake old', 'category_id' => $category->id, 'archived_at' => now()]);
        $filters = ['q' => 'brake', 'category_id' => $category->id, 'availability' => 'low', 'sort' => 'price_asc'];
        $cards = app(CatalogQuery::class)->search($staff, $filters + ['view' => 'cards']);
        $table = app(CatalogQuery::class)->search($staff, $filters + ['view' => 'table']);
        $this->assertSame([$wanted->id], $cards->pluck('id')->all());
        $this->assertSame($cards->pluck('id')->all(), $table->pluck('id')->all());
        $this->assertArrayNotHasKey('cost_centavos', $cards->first()->toArray());
    }

    public function test_manager_can_create_a_variant_without_accepting_browser_stock(): void
    {
        $this->signIn($this->manager());
        $data = $this->payload();
        $data['variants'][0]['stock'] = 999;
        $this->postJson('/products', $data)->assertCreated();
        $variant = Variant::where('sku', 'NEW-001')->firstOrFail();
        $this->assertSame(12345, $variant->price_centavos);
        $this->assertSame(0, $variant->stock);
    }

    public function test_staff_cannot_create_products_or_open_manager_form(): void
    {
        $this->signIn($this->staff());
        $this->get('/products/create')->assertForbidden();
        $this->postJson('/products', $this->payload())->assertForbidden();
        $this->assertSame(0, Product::count());
    }

    public function test_archiving_and_restoring_preserve_stock_and_variant_identity(): void
    {
        $manager = $this->manager();
        $variant = $this->variant(2);
        $service = app(ProductService::class);
        $service->archive($manager, $variant->product);
        $this->assertNotNull($variant->fresh()->archived_at);
        $this->assertSame(2, $variant->fresh()->stock);
        $this->assertSame(0, app(CatalogQuery::class)->search($this->staff(), [])->total());
        $service->restore($manager, $variant->product->fresh());
        $this->assertNull($variant->fresh()->archived_at);
        $this->assertSame(2, $variant->fresh()->stock);
        $this->assertSame(1, $variant->movements()->count());
    }

    public function test_archived_sku_is_not_available_for_a_new_product(): void
    {
        $variant = $this->variant();
        $variant->update(['archived_at' => now()]);
        $this->signIn($this->manager());
        $this->postJson('/products', $this->payload($variant->sku))->assertUnprocessable()->assertJsonValidationErrors('variants.0.sku');
    }

    public function test_invalid_fractional_centavo_price_is_rejected(): void
    {
        $this->signIn($this->manager());
        $payload = $this->payload();
        $payload['variants'][0]['price'] = '1.005';
        $this->postJson('/products', $payload)->assertUnprocessable()->assertJsonValidationErrors('variants.0.price');
    }

    public function test_inventory_page_renders_both_card_and_table_views(): void
    {
        $variant = $this->variant();
        $this->signIn($this->staff());
        $this->get('/inventory?view=cards')->assertOk()->assertSee($variant->sku);
        $this->get('/inventory?view=table')->assertOk()->assertSee($variant->sku);
        $this->get('/inventory/'.$variant->id)->assertOk()->assertSee($variant->sku);
    }

    public function test_default_order_uses_product_name_rather_than_sku(): void
    {
        $alpha = $this->variant();
        $alpha->update(['sku' => 'ZZZ-1']);
        $alpha->product->update(['name' => 'Alpha brake']);
        $zulu = $this->variant();
        $zulu->update(['sku' => 'AAA-1']);
        $zulu->product->update(['name' => 'Zulu mirror']);
        $this->assertSame([$alpha->id, $zulu->id], app(CatalogQuery::class)->search($this->staff(), [])->pluck('id')->all());
    }

    public function test_edit_cannot_attach_another_products_variant(): void
    {
        $left = $this->variant();
        $right = $this->variant();
        $this->signIn($this->manager());
        $payload = $this->payload($right->sku);
        $payload['variants'][0]['id'] = $right->id;
        $this->putJson('/products/'.$left->product_id, $payload)->assertUnprocessable();
        $this->assertSame($right->product_id, $right->fresh()->product_id);
    }
}
