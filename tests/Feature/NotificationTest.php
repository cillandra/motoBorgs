<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\CheckoutService;
use App\Services\Sales\ReturnService;
use App\Services\Settings\NotificationService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_return_notifications_dedupe_and_only_relevant_staff_and_managers_receive_them(): void
    {
        $manager = $this->manager();
        $owner = $this->staff();
        $other = $this->staff();
        $variant = $this->variant(5);
        $cart = $this->cart($owner, [$variant->id => 1])->refresh();
        $sale = app(CheckoutService::class)->complete($owner, $cart, 1, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => 10000])->sale;
        $return = app(ReturnService::class)->request($owner, $sale, [$sale->lines->first()->id => 1], 'Wrong fitment');
        $service = app(NotificationService::class);
        $service->publish('return_requested', 'return', (string) $return->id);
        $service->publish('return_requested', 'return', (string) $return->id);
        $this->assertSame(1, $service->forUser($owner)->count());
        $this->assertSame(1, $service->forUser($manager)->count());
        $this->assertSame(0, $service->forUser($other)->count());
        $this->assertSame(2, Notification::count());
    }

    public function test_stock_threshold_crossings_repeat_after_replenishment_but_retries_do_not_duplicate(): void
    {
        $manager = $this->manager();
        $staff = $this->staff();
        $variant = $this->variant(5);
        $ledger = app(StockLedger::class);
        $ledger->post($variant, -2, 'count_adjustment', 'low-one', $manager, 'Count fixture');
        $ledger->post($variant, -2, 'count_adjustment', 'low-one', $manager, 'Count fixture');
        $ledger->post($variant, 3, 'receive', 'up', $manager, 'Replenishment fixture');
        $ledger->post($variant, -3, 'count_adjustment', 'low-two', $manager, 'Count fixture');
        $this->assertSame(2, app(NotificationService::class)->forUser($staff)->count());
    }

    public function test_system_events_remain_manager_only_and_context_excludes_secrets(): void
    {
        $manager = $manager = $this->manager();
        $staff = $this->staff();
        $service = app(NotificationService::class);
        $service->publish('system', 'settings', 'security', ['event_id' => 'version-2', 'password' => 'SECRET', 'database' => 'PRIVATE', 'token' => 'TOKEN']);
        $this->assertSame(0, $service->forUser($staff)->count());
        $this->assertSame(1, $service->forUser($manager)->count());
        $this->assertStringNotContainsString('SECRET', Notification::all()->toJson());
        $this->assertStringNotContainsString('PRIVATE', Notification::all()->toJson());
    }

    public function test_notification_read_state_is_per_employee_and_other_ids_are_not_accessible(): void
    {
        $manager = $manager = $this->manager();
        $staff = $this->staff();
        $variant = $this->variant(5);
        $service = app(NotificationService::class);
        $service->publish('stock_attention', 'variant', (string) $variant->id, ['event_id' => 'fixture']);
        $mine = $service->forUser($staff)->first();
        $other = $service->forUser($manager)->first();
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $response = $this->getJson('/notifications')->assertOk();
        $this->assertSame([$mine->id], array_column($response->json('items'), 'id'));
        $this->postJson('/notifications/'.$other->id.'/read')->assertNotFound();
        $this->postJson('/notifications/'.$mine->id.'/read')->assertOk();
        $this->assertNull($other->fresh()->read_at);
        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_demoted_accounts_cannot_read_old_manager_only_notifications_even_with_a_stale_user_object(): void
    {
        $manager = $this->manager();
        $service = app(NotificationService::class);
        $service->publish('system', 'settings', 'security', ['event_id' => 'role-scope']);
        $this->assertSame(1, $service->forUser($manager)->count());
        User::whereKey($manager->id)->update(['role' => 'staff']);
        $this->assertSame(0, $service->forUser($manager)->count());
    }

    public function test_background_notification_polling_does_not_extend_an_idle_session(): void
    {
        $staff = $this->staff();
        $last = now()->subMinutes(14)->timestamp;
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => $last]);
        $this->getJson('/notifications')->assertOk()->assertSessionHas('moto_last_activity_at', $last);
        $this->travel(2)->minutes();
        $this->getJson('/notifications')->assertUnauthorized();
        $this->travelBack();
    }

    public function test_disabled_stock_notifications_do_not_affect_integrity_or_other_categories(): void
    {
        $manager = $manager = $this->manager();
        $staff = $this->staff();
        $settings = app(SettingsService::class);
        $read = $settings->read($manager, 'notifications');
        $settings->save($manager, 'notifications', array_merge($read['values'], ['stock' => false]), $read['version']);
        $variant = $this->variant(5);
        app(StockLedger::class)->post($variant, -2, 'count_adjustment', 'muted-low', $manager, 'Count fixture');
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, app(NotificationService::class)->forUser($staff)->count());
    }
}
