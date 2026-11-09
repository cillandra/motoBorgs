<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Pos\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class RouteCoverageTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private const MANAGER = ['activity.index', 'activity.export', 'activity.show', 'backups.store', 'backups.create', 'backups.show', 'backups.download', 'backups.restore', 'employees.index', 'employees.store', 'employees.create', 'employees.show', 'employees.update', 'employees.deactivate', 'employees.edit', 'employees.reactivate', 'employees.recovery', 'stock.form', 'stock.store', 'maintenance.edit', 'maintenance.update', 'products.store', 'products.create', 'products.update', 'products.archive', 'products.edit', 'products.restore', 'reports.index', 'reports.export', 'reports.print', 'refunds.store', 'returns.review', 'settings.receipt-preview', 'settings.update'];

    private const SHARED = ['password.change', 'password.update', 'checkout.status', 'checkout.store', 'dashboard', 'inventory.index', 'inventory.show', 'logout', 'notifications.index', 'notifications.all', 'notifications.read', 'pos.index', 'pos.cart', 'pos.mutate', 'pos.approval', 'pos.cancel', 'pos.hold', 'pos.resume', 'pos.lookup', 'products.image', 'returns.index', 'returns.show', 'sales.index', 'sales.show', 'sales.receipt', 'sales.invoice', 'inquiries.index', 'inquiries.update', 'returns.create', 'returns.store', 'settings.index', 'account.password', 'account.sessions', 'preferences.update'];

    private const PUBLIC = ['storefront.index', 'storefront.inquiry', 'storefront.image', 'login', 'login.store', 'recovery.form', 'recovery.complete'];

    private function applicationRoutes(): array
    {
        return collect(Route::getRoutes())->filter(fn ($route) => $route->getName() && ! str_starts_with($route->getName(), 'storage.'))->keyBy(fn ($route) => $route->getName())->all();
    }

    private function uri($route): string
    {
        return '/'.preg_replace_callback('/\{([^}]+)\}/', fn ($match) => $match[1] === 'section' ? 'general' : ($match[1] === 'key' ? 'coverage-attempt' : '1'), $route->uri());
    }

    public function test_every_named_application_route_has_an_explicit_role_and_authentication_contract(): void
    {
        $routes = $this->applicationRoutes();
        $actual = array_keys($routes);
        sort($actual);
        $expected = [...self::MANAGER, ...self::SHARED, ...self::PUBLIC];
        sort($expected);
        $this->assertSame($expected, $actual);
        foreach ([...self::MANAGER, ...self::SHARED] as $name) {
            $middleware = $routes[$name]->gatherMiddleware();
            $this->assertContains('auth', $middleware, $name);
            if ($name !== 'logout') {
                $this->assertContains('active', $middleware, $name);
                $this->assertContains('session.lifetime', $middleware, $name);
            }
            $this->assertSame(in_array($name, self::MANAGER, true), in_array('manager', $middleware, true), $name);
        }
    }

    public function test_guests_cannot_reach_any_private_route_including_hidden_actions_and_exports(): void
    {
        $routes = $this->applicationRoutes();
        foreach ([...self::MANAGER, ...self::SHARED] as $name) {
            $route = $routes[$name];
            $this->json($route->methods()[0], $this->uri($route))->assertUnauthorized();
        }
    }

    public function test_staff_cannot_reach_any_manager_route_even_when_binding_is_bypassed(): void
    {
        $this->withoutMiddleware(SubstituteBindings::class);
        $this->actingAs($this->staff())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $routes = $this->applicationRoutes();
        foreach (self::MANAGER as $name) {
            $route = $routes[$name];
            $this->json($route->methods()[0], $this->uri($route))->assertForbidden();
        }
    }

    public function test_both_roles_load_every_main_module_and_their_contextual_record_screens(): void
    {
        $manager = $this->manager();
        $staff = $this->staff();
        $variant = $this->variant(5);
        $cart = $this->cart($staff, [$variant->id => 1])->refresh();
        $sale = app(CheckoutService::class)->complete($staff, $cart, $cart->version, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => 10000])->sale;
        foreach ([$manager, $staff] as $actor) {
            $this->actingAs($actor)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
            foreach (['/dashboard', '/inventory', '/pos', '/sales', '/returns', '/settings', '/inventory/'.$variant->id, '/sales/'.$sale->id, '/sales/'.$sale->id.'/receipt', '/sales/'.$sale->id.'/returns', '/notifications'] as $uri) {
                $this->get($uri)->assertOk();
            }
        }
        $this->actingAs($manager);
        foreach (['/reports', '/reports/print', '/reports/export', '/employees', '/employees/create', '/employees/'.$staff->id, '/employees/'.$staff->id.'/edit', '/activity', '/activity/export', '/products/create', '/products/'.$variant->product_id.'/edit', '/inventory/'.$variant->id.'/stock', '/backups/create', '/maintenance'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_benchmark_seed_refuses_the_current_non_benchmark_database_before_mutation(): void
    {
        $manager = $this->manager();
        $this->artisan('moto:benchmark-seed', ['--variants' => 10000, '--sales' => 100000])->assertExitCode(1);
        $this->assertSame(1, User::count());
        $this->assertSame($manager->id, User::first()->id);
    }

    public function test_rendered_assets_are_local_and_do_not_require_a_network_font_or_script(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        preg_match_all('/<(?:script|link|img)\b[^>]*(?:src|href)="([^"]+)"/i', $html, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $url) {
            $this->assertMatchesRegularExpression('~^(?:/|http://(?:127\.0\.0\.1|localhost)(?::\d+)?/)~', $url);
        }
    }
}
