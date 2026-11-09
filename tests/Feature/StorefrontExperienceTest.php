<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Variant;
use App\Services\Customer\InquiryService;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class StorefrontExperienceTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_search_finds_motorcycle_models_part_numbers_and_active_options(): void
    {
        $variant = $this->variant();
        $variant->product->update(['name' => 'Road brake pads', 'compatibility' => 'Yamaha NMAX 155', 'part_number' => 'BORG-77']);
        $variant->update(['name' => 'Ceramic option']);
        foreach (['NMAX 155', 'BORG-77', 'Ceramic'] as $query) {
            $this->get('/?search='.urlencode($query))->assertOk()->assertSee('Road brake pads');
        }
        $this->get('/?search=%25')->assertOk()->assertSee('No products found');
    }

    public function test_one_product_card_groups_only_active_options_without_cost_fields(): void
    {
        $first = $this->variant();
        $second = Variant::factory()->create(['product_id' => $first->product_id, 'unit_id' => $first->unit_id, 'name' => 'Large', 'stock' => 0]);
        app(StockLedger::class)->post($second, 2, 'opening', 'experience:'.$second->id, null, 'Test opening stock');
        $hidden = Variant::factory()->create(['product_id' => $first->product_id, 'unit_id' => $first->unit_id, 'archived_at' => now(), 'name' => 'Hidden option', 'stock' => 0]);
        $response = $this->get('/')->assertOk()->assertDontSee('Hidden option');
        $products = $response->viewData('products');
        $this->assertCount(1, $products);
        $this->assertCount(2, $products->first()->variants);
        foreach ($products->first()->variants as $option) {
            $this->assertArrayNotHasKey('cost_centavos', $option->getAttributes());
        }
    }

    public function test_price_and_availability_filters_are_applied_and_invalid_page_recovers(): void
    {
        $cheap = $this->variant(3, 10000);
        $cheap->product->update(['name' => 'Affordable part']);
        $expensive = $this->variant(3, 50000);
        $expensive->product->update(['name' => 'Premium part']);
        $out = $this->variant(0, 20000);
        $out->product->update(['name' => 'Unavailable part']);
        $this->get('/?sort=price_desc&availability=available')->assertOk()->assertSeeInOrder(['Premium part', 'Affordable part'])->assertDontSee('Unavailable part');
        $this->get('/?sort=price_asc')->assertOk()->assertSeeInOrder(['Affordable part', 'Unavailable part', 'Premium part']);
        $this->get('/?page=999')->assertRedirect();
        $this->getJson('/?sort=unsafe')->assertUnprocessable();
    }

    public function test_catalog_validation_does_not_open_inquiry_errors_and_inquiry_errors_are_scoped(): void
    {
        $this->from('/')->get('/?sort=unsafe')->assertRedirect('/')->assertSessionHasErrors(['sort'], null, 'catalog');
        $this->from('/')->post('/inquiries', ['submission_key' => (string) Str::uuid(), 'topic' => 'general', 'name' => 'Visitor', 'message' => 'Question without contact'])->assertRedirect('/')->assertSessionHasErrors(['email'], null, 'inquiry');
    }

    public function test_approved_catalog_image_names_work_publicly_but_archived_images_do_not(): void
    {
        Storage::fake('local');
        $variant = $this->variant();
        $variant->product->update(['image_path' => 'products/demo-photo.png']);
        Storage::disk('local')->put('products/demo-photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aA1sAAAAASUVORK5CYII='));
        $this->get('/catalog/'.$variant->product_id.'/image')->assertOk()->assertHeader('content-type', 'image/png');
        $variant->product->update(['archived_at' => now()]);
        $this->get('/catalog/'.$variant->product_id.'/image')->assertNotFound();
    }

    public function test_inquiry_service_rejects_blank_values_after_normalizing_whitespace(): void
    {
        $this->expectException(ValidationException::class);
        app(InquiryService::class)->submit(['submission_key' => (string) Str::uuid(), 'name' => '  Visitor  ', 'email' => 'demo@example.test', 'topic' => 'general', 'message' => '    a    ']);
    }

    public function test_invalid_catalog_referrer_recovers_without_a_redirect_loop(): void
    {
        $this->from('/?sort=unsafe')->get('/?sort=unsafe')->assertRedirect('/')->assertSessionHasErrors(['sort'], null, 'catalog');
        $this->get('/')->assertOk();
    }

    public function test_category_links_and_page_recovery_keep_the_catalog_anchor(): void
    {
        $variant = $this->variant();
        $variant->product->update(['category_id' => Category::factory()->create()->id]);
        $this->get('/')->assertOk()->assertSee('category='.$variant->product->category_id.'#products', false)->assertDontSee('%23=products', false);
        $this->get('/?page=999')->assertRedirect('/?page=1#products');
    }
}
