<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\PaymentRecord;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Services\Pos\ApprovalService;
use App\Services\Pos\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function rejects(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Checkout unexpectedly succeeded.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_cash_checkout_commits_exact_variant_snapshots_payment_ledger_and_audit(): void
    {
        $cashier = $this->staff();
        $variant = $this->variant(3, 12500);
        $untouched = $this->variant(5);
        $cart = $this->cart($cashier, [$variant->id => 2])->refresh();
        $result = app(CheckoutService::class)->complete($cashier, $cart, 1, 'cash-checkout', ['method' => 'cash', 'received_centavos' => 30000, 'price_centavos' => 1, 'total_centavos' => 1]);
        $sale = $result->sale;
        $this->assertFalse($result->replayed);
        $this->assertSame(25000, $sale->total_centavos);
        $this->assertSame(5000, $sale->payment->change_centavos);
        $this->assertSame($variant->sku, $sale->lines->first()->sku);
        $this->assertSame(1, $variant->fresh()->stock);
        $this->assertSame(5, $untouched->fresh()->stock);
        $this->assertSame('completed', $cart->fresh()->status);
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
        $this->assertTrue(AuditEvent::where('type', 'Sale completed')->where('actor_id', $cashier->id)->exists());
        $variant->product->update(['name' => 'Renamed after sale']);
        $this->assertNotSame('Renamed after sale', $sale->lines->first()->product_name);
    }

    public function test_all_manual_methods_require_verified_outcome_reference_and_enabled_policy(): void
    {
        $cashier = $this->staff();
        $service = app(CheckoutService::class);
        foreach (['gcash', 'qrph', 'debit', 'credit'] as $method) {
            $variant = $this->variant(3);
            $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
            $this->rejects(422, fn () => $service->complete($cashier, $cart, 1, $method.'-unverified', ['method' => $method, 'verified' => false, 'reference' => 'DEMO-01', 'received_centavos' => 10000]));
            $this->rejects(422, fn () => $service->complete($cashier, $cart, 1, $method.'-missing-ref', ['method' => $method, 'verified' => true, 'received_centavos' => 10000]));
            $result = $service->complete($cashier, $cart, 1, $method.'-verified', ['method' => $method, 'verified' => true, 'reference' => 'DEMO-01', 'received_centavos' => 10000]);
            $this->assertSame($method, $result->sale->payment->method);
            $this->assertSame(0, $result->sale->payment->change_centavos);
        }
        Setting::create(['section' => 'sales', 'value' => ['methods' => ['cash']]]);
        $variant = $this->variant();
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $this->rejects(422, fn () => $service->complete($cashier, $cart, 1, 'disabled-method', ['method' => 'gcash', 'verified' => true, 'reference' => 'DEMO-01', 'received_centavos' => 10000]));
        $this->assertSame(4, Sale::count());
    }

    public function test_cash_short_stale_cart_price_change_and_archive_are_rejected_without_deduction(): void
    {
        $cashier = $this->staff();
        $variant = $this->variant();
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $service = app(CheckoutService::class);
        $this->rejects(422, fn () => $service->complete($cashier, $cart, 1, 'short', ['method' => 'cash', 'received_centavos' => 9999]));
        $this->rejects(409, fn () => $service->complete($cashier, $cart, 99, 'stale', ['method' => 'cash', 'received_centavos' => 10000]));
        $variant->update(['price_centavos' => 11000]);
        $this->rejects(409, fn () => $service->complete($cashier, $cart, 1, 'price-changed', ['method' => 'cash', 'received_centavos' => 11000]));
        $variant->update(['price_centavos' => 10000, 'archived_at' => now()]);
        $this->rejects(409, fn () => $service->complete($cashier, $cart, 1, 'archived', ['method' => 'cash', 'received_centavos' => 10000]));
        $this->assertSame(0, Sale::count());
        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_lost_response_retry_and_status_return_original_sale_but_changed_key_content_conflicts(): void
    {
        $cashier = $this->staff();
        $variant = $this->variant();
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $service = app(CheckoutService::class);
        $first = $service->complete($cashier, $cart, 1, 'lost-response', ['method' => 'cash', 'received_centavos' => 10000]);
        $again = $service->complete($cashier, $cart, 1, 'lost-response', ['method' => 'cash', 'received_centavos' => 10000]);
        $this->assertTrue($again->replayed);
        $this->assertSame($first->sale->id, $again->sale->id);
        $this->assertSame($first->sale->id, $service->status($cashier, 'lost-response')->id);
        $this->assertNull($service->status($this->staff(), 'lost-response'));
        $this->rejects(409, fn () => $service->complete($cashier, $cart, 1, 'lost-response', ['method' => 'cash', 'received_centavos' => 20000]));
        $this->rejects(409, fn () => $service->complete($cashier, $cart, 1, 'new-key-same-cart', ['method' => 'cash', 'received_centavos' => 10000]));
        $this->assertSame(1, Sale::count());
        $this->assertSame(2, $variant->fresh()->stock);
    }

    public function test_critical_audit_failure_rolls_back_every_sale_write_and_stock_deduction(): void
    {
        $cashier = $this->staff();
        $variant = $this->variant();
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $count = AuditEvent::count();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->type === 'Sale completed') {
                throw new RuntimeException('Injected audit storage failure');
            }
        });
        try {
            app(CheckoutService::class)->complete($cashier, $cart, 1, 'audit-failure', ['method' => 'cash', 'received_centavos' => 10000]);
            $this->fail('Checkout committed without its critical audit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected audit storage failure', $error->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, PaymentRecord::count());
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame($count, AuditEvent::count());
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame('draft', $cart->fresh()->status);
    }

    public function test_cart_discount_is_allocated_exactly_and_approval_consumed_in_the_sale_transaction(): void
    {
        $cashier = $this->staff();
        $first = $this->variant(3, 100);
        $second = $this->variant(3, 100);
        $cart = $this->cart($cashier, [$first->id => 1, $second->id => 1])->refresh();
        $approval = app(ApprovalService::class)->approve($this->manager(), 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 1], 'Rounding demonstration');
        $this->rejects(422, fn () => app(CheckoutService::class)->complete($cashier, $cart, 2, 'discount-short', ['method' => 'cash', 'received_centavos' => 198]));
        $this->assertNull($approval->fresh()->consumed_at);
        $result = app(CheckoutService::class)->complete($cashier, $cart, 2, 'discount-checkout', ['method' => 'cash', 'received_centavos' => 200]);
        $this->assertSame([99, 100], $result->sale->lines()->orderBy('id')->pluck('total_centavos')->all());
        $this->assertSame(199, $result->sale->total_centavos);
        $this->assertSame($approval->id, $result->sale->approval_id);
        $this->assertNotNull($approval->fresh()->consumed_at);
    }

    public function test_http_checkout_receipt_copy_and_ownership_preserve_the_sale_identifier(): void
    {
        $cashier = $this->staff();
        $variant = $this->variant();
        $cart = $this->cart($cashier, [$variant->id => 1])->refresh();
        $this->actingAs($cashier)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $key = (string) Str::uuid();
        $response = $this->postJson('/checkout/'.$cart->id, ['version' => 1, 'request_key' => $key, 'method' => 'cash', 'received' => '120.00', 'total' => '0.01'])->assertCreated();
        $id = $response->json('sale_id');
        $this->get('/sales/'.$id.'/receipt?width=58')->assertOk()->assertSee('Academic demonstration receipt')->assertSee('MOTO-'.str_pad($id, 8, '0', STR_PAD_LEFT))->assertSee('ORIGINAL PREVIEW');
        $this->get('/sales/'.$id.'/receipt')->assertOk()->assertSee('COPY');
        $this->getJson('/checkout/status/'.$key)->assertOk()->assertJsonPath('sale_id', $id);
        $this->postJson('/checkout/'.$cart->id, ['version' => 1, 'request_key' => $key, 'method' => 'cash', 'received' => '120.00'])->assertOk()->assertJsonPath('sale_id', $id);
        $this->assertSame(1, Sale::count());
        $this->actingAs($this->staff())->get('/sales/'.$id.'/receipt')->assertForbidden();
    }
}
