<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\AuditEvent;
use App\Services\Pos\ApprovalService;
use App\Services\Pos\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class CartApprovalTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function rejects(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Operation unexpectedly succeeded.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_add_uses_server_price_and_enforces_quantity_stock_and_cart_version(): void
    {
        $cashier = $this->staff();
        $variant = $this->variant(3, 12500);
        $cart = $this->cart($cashier, [])->refresh();
        $service = app(CartService::class);
        $result = $service->mutate($cashier, $cart, 1, ['action' => 'add', 'variant_id' => $variant->id, 'quantity' => 2, 'price_centavos' => 1]);
        $this->assertSame(12500, $result->lines()->first()->price_centavos);
        $this->assertSame(2, $result->version);
        $this->rejects(409, fn () => $service->mutate($cashier, $cart, 1, ['action' => 'remove', 'variant_id' => $variant->id]));
        $this->rejects(422, fn () => $service->mutate($cashier, $cart, 2, ['action' => 'set', 'variant_id' => $variant->id, 'quantity' => -1]));
        $this->rejects(409, fn () => $service->mutate($cashier, $cart, 2, ['action' => 'set', 'variant_id' => $variant->id, 'quantity' => 4]));
    }

    public function test_other_cashier_cannot_access_or_edit_a_cart(): void
    {
        $cart = $this->cart($this->staff(), [])->refresh();
        $other = $this->staff();
        $this->rejects(403, fn () => app(CartService::class)->quote($other, $cart));
        $this->rejects(403, fn () => app(CartService::class)->mutate($other, $cart, 1, ['action' => 'refresh_prices']));
    }

    public function test_archived_and_zero_stock_variants_cannot_be_added(): void
    {
        $cashier = $this->staff();
        $cart = $this->cart($cashier, [])->refresh();
        $variant = $this->variant(0);
        $this->rejects(409, fn () => app(CartService::class)->mutate($cashier, $cart, 1, ['action' => 'add', 'variant_id' => $variant->id, 'quantity' => 1]));
        $variant->update(['stock' => 2, 'archived_at' => now()]);
        $this->rejects(409, fn () => app(CartService::class)->mutate($cashier, $cart, 1, ['action' => 'add', 'variant_id' => $variant->id, 'quantity' => 1]));
    }

    public function test_hold_persists_without_reserving_and_resume_warns_on_price_and_stock_changes(): void
    {
        $cashier = $this->staff();
        $variant = $this->variant(3);
        $cart = $this->cart($cashier, [$variant->id => 2])->refresh();
        $service = app(CartService::class);
        $held = $service->hold($cashier, $cart, 1);
        $this->assertSame('held', $held->status);
        $this->assertSame(3, $variant->fresh()->stock);
        $variant->update(['stock' => 1, 'price_centavos' => 12000]);
        $resumed = $service->resume($cashier, $held);
        $quote = $service->quote($cashier, $resumed);
        $this->assertCount(2, $quote['warnings']);
        $this->assertSame(24000, $quote['subtotal']);
        $service->mutate($cashier, $resumed, $resumed->version, ['action' => 'refresh_prices']);
        $this->assertCount(1, $service->quote($cashier, $cart->fresh())['warnings']);
    }

    public function test_discount_requires_manager_password_and_is_capped_at_ten_percent(): void
    {
        $cashier = $this->staff();
        $manager = $this->manager();
        $variant = $this->variant();
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $service = app(ApprovalService::class);
        $this->rejects(422, fn () => $service->approve($manager, 'wrong', $cart, 1, 'discount', ['discount_centavos' => 500], 'Courtesy discount'));
        $this->rejects(422, fn () => $service->approve($cashier, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 500], 'Courtesy discount'));
        $this->rejects(422, fn () => $service->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 1001], 'Courtesy discount'));
        $approval = $service->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 1000], 'Courtesy discount');
        $this->assertSame($cashier->id, $approval->requester_id);
        $this->assertSame($manager->id, $approval->approver_id);
        $this->assertSame(1000, $cart->fresh()->discount_centavos);
        $this->assertSame(2, $approval->cart_version);
        $this->assertSame($cashier->id, $cart->fresh()->cashier_id);
        $this->assertTrue(AuditEvent::where('type', 'Manager approval granted')->where('actor_id', $manager->id)->exists());
    }

    public function test_expired_consumed_and_changed_cart_approvals_cannot_be_reused(): void
    {
        $cashier = $this->staff();
        $manager = $this->manager();
        $variant = $this->variant();
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $service = app(ApprovalService::class);
        $approval = $service->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 500], 'Courtesy discount');
        $this->travel(5)->minutes();
        $this->rejects(409, fn () => $service->consume($cashier, $approval, $cart->fresh(), 'discount'));
        $this->travelBack();
        $service->consume($cashier, $approval, $cart->fresh(), 'discount');
        $this->rejects(409, fn () => $service->consume($cashier, $approval, $cart->fresh(), 'discount'));
        $approval = $service->approve($manager, 'Unit test passphrase 2026!', $cart, 2, 'discount', ['discount_centavos' => 500], 'Courtesy discount');
        app(CartService::class)->mutate($cashier, $cart, 3, ['action' => 'set', 'variant_id' => $variant->id, 'quantity' => 2]);
        $this->assertSame(0, $cart->fresh()->discount_centavos);
        $this->assertNotNull($approval->fresh()->invalidated_at);
        $this->rejects(409, fn () => $service->consume($cashier, $approval, $cart->fresh(), 'discount'));
    }

    public function test_cancellation_needs_a_reason_and_void_exception_needs_approval(): void
    {
        $cashier = $this->staff();
        $cart = $this->cart($cashier, [])->refresh();
        $service = app(CartService::class);
        $this->rejects(422, fn () => $service->cancel($cashier, $cart, 1, '', null));
        $this->rejects(409, fn () => $service->cancel($cashier, $cart, 1, 'Restricted void', 999));
        $approval = app(ApprovalService::class)->approve($this->manager(), 'Unit test passphrase 2026!', $cart, 1, 'cancel_exception', [], 'Restricted void');
        $service->cancel($cashier, $cart, 1, 'Restricted void', $approval->id);
        $this->assertSame('cancelled', $cart->fresh()->status);
        $this->assertNotNull($approval->fresh()->consumed_at);
    }

    public function test_http_pos_approval_preserves_staff_session_and_barcode_lookup_is_exact(): void
    {
        $staff = $this->staff();
        $manager = $this->manager();
        $variant = $this->variant();
        $variant->update(['barcode' => '123456789']);
        $cart = $this->cart($staff, [$variant->id => 1])->refresh();
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/pos?cart='.$cart->id)->assertOk()->assertSee('Current order');
        $this->getJson('/pos/lookup?code=123456789')->assertOk()->assertJsonPath('variant_id', $variant->id);
        $this->getJson('/pos/lookup?code=123456')->assertNotFound();
        $this->postJson('/pos/carts/'.$cart->id.'/approval', ['manager_username' => $manager->username, 'manager_password' => 'Unit test passphrase 2026!', 'version' => 1, 'action' => 'discount', 'discount' => '5.00', 'reason' => 'Courtesy discount'])->assertOk();
        $this->assertAuthenticatedAs($staff);
        $this->assertSame(1, Approval::count());
    }
}
