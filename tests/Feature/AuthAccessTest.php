<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class AuthAccessTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_active_staff_signs_in_with_the_account_role(): void
    {
        $user = $this->staff();
        $this->post('/login', ['username' => $user->username, 'password' => 'Unit test passphrase 2026!', 'role' => 'manager'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('staff', auth()->user()->role);
    }

    public function test_inactive_account_is_rejected_without_flashing_password(): void
    {
        $user = $this->staff();
        $user->update(['active' => false]);
        $this->from('/login')->post('/login', ['username' => $user->username, 'password' => 'Unit test passphrase 2026!'])
            ->assertRedirect('/login')->assertSessionHasErrors('username')
            ->assertSessionHas('_old_input.username', $user->username)->assertSessionMissing('_old_input.password');
        $this->assertGuest();
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['username' => 'missing-account', 'password' => 'incorrect'])->assertSessionHasErrors('username');
        }
        $this->post('/login', ['username' => 'missing-account', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_idle_session_expires_on_the_server(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->subMinutes(16)->timestamp]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_absolute_session_expires_despite_recent_activity(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->withSession(['moto_signed_in_at' => now()->subHours(8)->subSecond()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_staff_cannot_call_manager_endpoint(): void
    {
        Route::middleware(['web', 'auth', 'active', 'session.lifetime', 'manager'])->get('/test-manager-access', fn () => response()->json(['ok' => true]));
        $this->actingAs($this->staff())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp])->getJson('/test-manager-access')->assertForbidden();
    }

    public function test_staff_cannot_read_another_cashiers_sale(): void
    {
        $owner = $this->staff();
        $sale = Sale::create(['cart_id' => $this->cart($owner, [])->id, 'cashier_id' => $owner->id, 'cashier_name' => $owner->name, 'request_key' => 'policy-test', 'request_hash' => str_repeat('a', 64), 'subtotal_centavos' => 100, 'total_centavos' => 100]);
        $this->assertFalse(Gate::forUser($this->staff())->allows('view', $sale));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $sale));
        $this->assertTrue(Gate::forUser($this->manager())->allows('view', $sale));
    }

    public function test_long_passphrases_are_allowed_and_weak_or_short_ones_rejected(): void
    {
        $rules = ['password' => PasswordPolicy::rules()];
        $this->assertTrue(Validator::make(['password' => 'short-password'], $rules)->fails());
        $this->assertTrue(Validator::make(['password' => 'passwordpassword'], $rules)->fails());
        $this->assertTrue(Validator::make(['password' => str_repeat('A long unique passphrase! ', 8)], $rules)->passes());
    }

    public function test_logout_ends_authentication(): void
    {
        $this->actingAs($this->staff())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_page_renders_required_controls(): void
    {
        $this->get('/login')->assertOk()->assertSee('Welcome back')->assertSee('autocomplete="username"', false)->assertSee('autocomplete="current-password"', false);
    }

    public function test_authenticated_dashboard_renders_the_account_role(): void
    {
        $this->actingAs($this->manager())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/dashboard')->assertOk()->assertSee('Manager workspace')->assertSee('Dashboard');
    }

    public function test_csrf_is_required_outside_the_test_bypass(): void
    {
        $this->app['env'] = 'local';
        $this->withMiddleware()->post('/login', ['username' => 'manager', 'password' => 'incorrect'])->assertStatus(419);
    }
}
