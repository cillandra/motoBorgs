<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Services\Catalog\ProductImageService;
use App\Services\Pos\ApprovalService;
use App\Services\Settings\PreferenceService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function signedIn($user): static
    {
        return $this->actingAs($user)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid settings accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_all_role_appropriate_sections_exist_and_global_sections_are_denied_to_staff(): void
    {
        $manager = $this->manager();
        $staff = $this->staff();
        $this->signedIn($manager);
        foreach (['general', 'sales', 'receipts', 'inventory', 'notifications', 'security', 'backup', 'system', 'preferences', 'account'] as $section) {
            $this->get('/settings?section='.$section)->assertOk();
        }
        $this->signedIn($staff);
        foreach (['preferences', 'account', 'about'] as $section) {
            $this->get('/settings?section='.$section)->assertOk();
        }
        foreach (['general', 'sales', 'receipts', 'inventory', 'notifications', 'security', 'backup', 'system'] as $section) {
            $this->get('/settings?section='.$section)->assertForbidden();
            $this->putJson('/settings/'.$section, ['version' => 0, 'values' => []])->assertForbidden();
        }
    }

    public function test_settings_validate_values_and_reject_stale_versions(): void
    {
        $manager = $this->manager();
        $service = app(SettingsService::class);
        $before = $service->read($manager, 'general');
        $values = array_merge($before['values'], ['shop_name' => 'School demonstration shop', 'address' => 'Demo address']);
        $saved = $service->save($manager, 'general', $values, $before['version']);
        $this->assertSame('School demonstration shop', $saved['values']['shop_name']);
        try {
            $service->save($manager, 'general', $values, $before['version']);
            $this->fail('Stale settings accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        foreach ([['shop_name' => ''], ['contact' => 'not a phone'], ['currency' => 'USD'], ['timezone' => 'UTC']] as $invalid) {
            $this->invalid(fn () => $service->save($manager, 'general', array_merge($values, $invalid), $saved['version']));
        }
        $sales = $service->read($manager, 'sales');
        $this->invalid(fn () => $service->save($manager, 'sales', array_merge($sales['values'], ['methods' => []]), $sales['version']));
    }

    public function test_integrity_controls_and_security_bounds_cannot_be_weakened(): void
    {
        $manager = $this->manager();
        $service = app(SettingsService::class);
        foreach (['inventory' => ['allow_negative_stock' => true], 'receipts' => ['tax_enabled' => true], 'sales' => ['manual_verification' => false], 'security' => ['password_min' => 8]] as $section => $invalid) {
            $read = $service->read($manager, $section);
            $this->invalid(fn () => $service->save($manager, $section, array_merge($read['values'], $invalid), $read['version']));
        }
        $this->signedIn($manager);
        $security = $service->read($manager, 'security');
        $values = array_merge($security['values'], ['idle_minutes' => 5]);
        $this->putJson('/settings/security', ['version' => $security['version'], 'values' => $values, 'manager_password' => 'wrong'])->assertUnprocessable();
        $this->assertSame(15, $service->read($manager, 'security')['values']['idle_minutes']);
        $this->putJson('/settings/security', ['version' => $security['version'], 'values' => $values, 'manager_password' => 'Unit test passphrase 2026!'])->assertOk();
        $this->withSession(['moto_last_activity_at' => now()->subMinutes(6)->timestamp])->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_saved_discount_cap_is_enforced_by_manager_approval(): void
    {
        $manager = $this->manager();
        $staff = $this->staff();
        $service = app(SettingsService::class);
        $sales = $service->read($manager, 'sales');
        $service->save($manager, 'sales', array_merge($sales['values'], ['discount_cap' => 5]), $sales['version']);
        $variant = $this->variant(5);
        $cart = $this->cart($staff, [$variant->id => 1])->refresh();
        try {
            app(ApprovalService::class)->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 1000], 'Too much discount');
            $this->fail('Configured discount cap ignored.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        app(ApprovalService::class)->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 500], 'Allowed discount');
        $this->assertSame(500, $cart->fresh()->discount_centavos);
    }

    public function test_personal_preferences_are_owned_and_apply_to_layout_and_list_rows(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $preferences = app(PreferenceService::class);
        $values = ['theme' => 'light', 'density' => 'compact', 'rows' => 10, 'reduced_motion' => true, 'sound' => false];
        $preferences->save($staff, $values);
        $this->assertNull($other->fresh()->preference);
        $this->assertSame('light', $staff->fresh()->preference->value['theme']);
        $this->invalid(fn () => $preferences->save($staff, $values + ['user_id' => $other->id]));
        $this->signedIn($staff)->get('/dashboard')->assertOk()->assertSee('data-theme="light"', false)->assertSee('data-density="compact"', false)->assertSee('data-reduced-motion="true"', false);
        for ($i = 0; $i < 11; $i++) {
            $this->variant(5);
        }
        $this->get('/inventory?view=table')->assertOk()->assertViewHas('variants', fn ($variants) => $variants->perPage() === 10 && $variants->total() === 11);
    }

    public function test_own_password_change_verifies_current_password_revokes_sessions_and_relogs(): void
    {
        $staff = $this->staff();
        $this->signedIn($staff);
        DB::table('sessions')->insert(['id' => 'old-staff-session', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $payload = ['current_password' => 'incorrect', 'password' => 'My private new school passphrase!', 'password_confirmation' => 'My private new school passphrase!'];
        $this->postJson('/settings/account/password', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('Unit test passphrase 2026!', $staff->fresh()->password));
        $this->postJson('/settings/account/password', array_merge($payload, ['current_password' => 'Unit test passphrase 2026!']))->assertOk()->assertJsonPath('redirect', route('login'));
        $this->assertTrue(Hash::check($payload['password'], $staff->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->assertGuest();
    }

    public function test_inventory_defaults_and_saved_image_limit_apply_to_real_product_flows(): void
    {
        $manager = $this->manager();
        $settings = app(SettingsService::class);
        $read = $settings->read($manager, 'inventory');
        $settings->save($manager, 'inventory', array_merge($read['values'], ['threshold' => 7, 'max_image_mb' => 1]), $read['version']);
        $this->signedIn($manager)->get('/products/create')->assertOk()->assertSee('value="7"', false)->assertSee('up to 1 MB');
        Storage::fake('local');
        $product = $this->variant(5)->product;
        $this->invalid(fn () => app(ProductImageService::class)->replace($product, UploadedFile::fake()->image('oversize.png', 16, 16)->size(2048)));
        $this->assertNull($product->fresh()->image_path);
    }

    public function test_receipt_settings_preview_uses_saved_layout_and_does_not_create_sales(): void
    {
        $manager = $this->manager();
        $settings = app(SettingsService::class);
        $read = $settings->read($manager, 'receipts');
        $settings->save($manager, 'receipts', array_merge($read['values'], ['width' => 58, 'copies' => 2, 'show_logo' => false, 'footer' => 'School demonstration only']), $read['version']);
        $this->signedIn($manager)->get('/settings/receipt-preview')->assertOk()->assertSee('School demonstration only')->assertSee('data-receipt-copies="2"', false)->assertDontSee('class="receipt-logo"', false);
        $this->assertSame(0, Sale::count());
    }

    public function test_about_and_system_pages_do_not_expose_private_diagnostics(): void
    {
        $this->signedIn($this->staff())->get('/settings?section=about')->assertOk()->assertSee('About MOTO')->assertDontSee('DB_PASSWORD')->assertDontSee(storage_path());
        $this->signedIn($this->manager())->get('/settings?section=system')->assertOk()->assertSee('System status')->assertDontSee(config('database.connections.mysql.password'))->assertDontSee(base_path());
    }
}
