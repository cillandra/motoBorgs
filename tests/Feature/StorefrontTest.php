<?php

namespace Tests\Feature;

use App\Models\CustomerInquiry;
use App\Models\Setting;
use App\Services\Pos\CheckoutService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
    }

    private function inquiry(array $changes = []): array
    {
        return array_replace(['submission_key' => (string) Str::uuid(), 'name' => 'Demo Customer', 'email' => 'customer@example.test', 'phone' => '', 'topic' => 'general', 'message' => 'Please tell me more about your products.', 'website' => ''], $changes);
    }

    public function test_guests_browse_only_active_public_catalog_and_demo_services(): void
    {
        $active = $this->variant(8, 12000);
        $active->product->update(['name' => 'Public motorcycle part']);
        $hidden = $this->variant();
        $hidden->product->update(['name' => 'Private archived part', 'archived_at' => now()]);
        $html = $this->get('/')->assertOk()->assertSee('Public motorcycle part')->assertSee('Demonstration content')->assertDontSee('Private archived part')->getContent();
        $this->assertStringNotContainsString('cost_centavos', $html);
        $this->assertStringNotContainsString('8 in stock', $html);
        $this->get('/?search=unknown-product')->assertOk()->assertSee('No products found');
    }

    public function test_inquiry_is_saved_once_and_replay_with_changed_content_is_rejected(): void
    {
        $data = $this->inquiry();
        $this->postJson('/inquiries', $data)->assertCreated()->assertJsonStructure(['reference', 'message']);
        $this->postJson('/inquiries', $data)->assertOk();
        $this->assertDatabaseCount('customer_inquiries', 1);
        $this->postJson('/inquiries', array_replace($data, ['message' => 'Different concern']))->assertConflict();
        $this->assertDatabaseHas('customer_inquiries', ['email' => 'customer@example.test', 'status' => 'new']);
    }

    public function test_contact_and_eligible_product_or_service_are_required_and_spam_is_not_saved(): void
    {
        $this->postJson('/inquiries', $this->inquiry(['email' => '', 'phone' => '']))->assertUnprocessable();
        $variant = $this->variant();
        $variant->update(['archived_at' => now()]);
        $this->postJson('/inquiries', $this->inquiry(['topic' => 'product', 'variant_id' => $variant->id]))->assertUnprocessable();
        $this->postJson('/inquiries', $this->inquiry(['topic' => 'service', 'service_name' => 'Nonexistent service']))->assertUnprocessable();
        $this->postJson('/inquiries', $this->inquiry(['website' => 'spam.example.test']))->assertUnprocessable();
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_product_and_service_inquiries_use_server_controlled_subjects(): void
    {
        $variant = $this->variant();
        $this->postJson('/inquiries', $this->inquiry(['topic' => 'product', 'variant_id' => $variant->id, 'subject' => 'Forged subject']))->assertCreated();
        $this->assertSame($variant->product->name.' · '.$variant->name, CustomerInquiry::first()->subject);
        $this->postJson('/inquiries', $this->inquiry(['topic' => 'service', 'service_name' => 'Parts compatibility check']))->assertCreated();
    }

    public function test_only_authorized_employees_access_inbox_and_stale_handling_is_rejected(): void
    {
        $this->postJson('/inquiries', $this->inquiry())->assertCreated();
        $inquiry = CustomerInquiry::first();
        $this->getJson('/customer-inquiries')->assertUnauthorized();
        $this->actingAs($this->staff());
        $this->get('/customer-inquiries')->assertOk()->assertSee('Demo Customer');
        $this->putJson('/customer-inquiries/'.$inquiry->id, ['version' => 1, 'status' => 'handled'])->assertOk();
        $this->putJson('/customer-inquiries/'.$inquiry->id, ['version' => 1, 'status' => 'new'])->assertConflict();
        $this->assertSame('handled', $inquiry->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['type' => 'Customer inquiry updated']);
    }

    public function test_public_content_is_configurable_only_by_manager_with_safe_contact_links(): void
    {
        $this->actingAs($this->staff())->get('/settings?section=storefront')->assertForbidden();
        $this->actingAs($this->manager());
        $this->get('/settings?section=storefront')->assertOk();
        $values = SettingsService::DEFAULTS['storefront'];
        $this->putJson('/settings/storefront', ['version' => 0, 'values' => array_replace($values, ['messenger_page' => 'javascript:alert(1)'])])->assertUnprocessable();
        $this->putJson('/settings/storefront', ['version' => 0, 'values' => array_replace($values, ['phone' => '+639171234567', 'messenger_page' => 'demo.page', 'demo_content' => false])])->assertOk();
        $this->get('/')->assertOk()->assertSee('tel:+639171234567', false)->assertSee('https://m.me/demo.page', false);
        $this->assertDatabaseHas('settings', ['section' => 'storefront']);
    }

    public function test_inquiry_throttle_limits_repeated_submissions(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/inquiries', $this->inquiry())->assertCreated();
        }
        $this->postJson('/inquiries', $this->inquiry())->assertTooManyRequests();
        $this->assertDatabaseCount('customer_inquiries', 5);
    }

    public function test_saved_service_inquiry_replays_even_after_service_is_removed(): void
    {
        $data = $this->inquiry(['topic' => 'service', 'service_name' => 'Parts compatibility check']);
        $this->postJson('/inquiries', $data)->assertCreated();
        Setting::create(['section' => 'storefront', 'value' => ['services' => ''], 'version' => 1]);
        $this->postJson('/inquiries', $data)->assertOk();
        $this->assertDatabaseCount('customer_inquiries', 1);
    }

    public function test_phone_only_inquiry_requires_digits_and_accepts_normal_formatting(): void
    {
        $this->postJson('/inquiries', $this->inquiry(['email' => '', 'phone' => '-------']))->assertUnprocessable();
        $this->postJson('/inquiries', $this->inquiry(['email' => '', 'phone' => '+63 (917) 123-4567']))->assertCreated();
    }

    public function test_invoice_obeys_sale_access_and_does_not_change_stock_or_sales(): void
    {
        $staff = $this->staff();
        $variant = $this->variant(5);
        $cart = $this->cart($staff, [$variant->id => 1])->refresh();
        $sale = app(CheckoutService::class)->complete($staff, $cart, $cart->version, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => 10000])->sale;
        $this->actingAs($staff)->get('/sales/'.$sale->id.'/invoice')->assertOk()->assertSee('Academic demonstration invoice')->assertSee($sale->lines->first()->product_name)->assertSee('data-receipt-preview', false);
        $this->actingAs($this->staff())->get('/sales/'.$sale->id.'/invoice')->assertForbidden();
        $this->actingAs($this->manager())->get('/sales/'.$sale->id.'/invoice')->assertOk();
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(4, $variant->fresh()->stock);
    }

    public function test_documented_profile_is_available_for_older_settings_and_stays_labeled_pending(): void
    {
        Setting::create(['section' => 'storefront', 'value' => ['about' => 'Existing shop introduction', 'demo_content' => false], 'version' => 1]);
        $this->get('/')->assertOk()->assertSee('Existing shop introduction')->assertSee('2021')
            ->assertSee('Our history')->assertSee('Our mission')->assertSee('Our vision')->assertSee('Our goals')
            ->assertSee('await owner confirmation')->assertDontSee('To improve the accuracy of sales and inventory records.');
    }

    public function test_manager_can_update_or_hide_profile_sections_and_public_text_is_escaped(): void
    {
        $values = array_replace(SettingsService::DEFAULTS['storefront'], ['history' => 'Owner story <script>alert(1)</script>', 'mission' => 'Owner mission', 'vision' => 'Owner vision', 'goals' => "First goal\n\nSecond goal", 'profile_pending' => false]);
        $this->actingAs($this->staff())->putJson('/settings/storefront', ['version' => 0, 'values' => $values])->assertForbidden();
        $this->actingAs($this->manager())->putJson('/settings/storefront', ['version' => 0, 'values' => $values])->assertOk();
        $this->get('/')->assertOk()->assertSee('Owner story <script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Owner mission')->assertSee('Owner vision')->assertSeeInOrder(['First goal', 'Second goal'])->assertDontSee('await owner confirmation');
        $values = array_replace($values, ['history' => '', 'mission' => '', 'vision' => '', 'goals' => '']);
        $this->putJson('/settings/storefront', ['version' => 1, 'values' => $values])->assertOk();
        $this->get('/')->assertOk()->assertDontSee('Our history')->assertDontSee('Our mission')->assertDontSee('Our vision')->assertDontSee('Our goals');
        $this->get('/settings?section=storefront')->assertOk()->assertSee('Shop history')->assertSee('Mission')->assertSee('Vision')->assertSee('Customer-facing goals');
    }

    public function test_profile_text_limits_are_validated_before_saving(): void
    {
        $values = array_replace(SettingsService::DEFAULTS['storefront'], ['history' => str_repeat('x', 2001), 'mission' => str_repeat('x', 1001)]);
        $this->actingAs($this->manager())->putJson('/settings/storefront', ['version' => 0, 'values' => $values])->assertUnprocessable()->assertJsonValidationErrors(['values.history', 'values.mission']);
        $this->assertDatabaseCount('settings', 0);
    }
}
