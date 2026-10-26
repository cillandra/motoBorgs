<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    public function test_receive_is_posted_once_and_reconciles_to_the_ledger(): void
    {
        $actor = $this->manager();
        $variant = $this->variant(3);
        $ledger = app(StockLedger::class);
        $first = $ledger->receive($actor, $variant, 5, 'DEL-01', 'New stock', 'receive-one');
        $again = $ledger->receive($actor, $variant, 5, 'DEL-01', 'New stock', 'receive-one');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(8, $variant->fresh()->stock);
        $this->assertSame(8, (int) $variant->movements()->sum('delta'));
        $this->assertSame(2, $variant->movements()->count());
    }

    public function test_reusing_a_movement_key_for_different_quantity_is_a_conflict(): void
    {
        $actor = $this->manager();
        $variant = $this->variant(3);
        app(StockLedger::class)->receive($actor, $variant, 5, 'DEL-01', 'New stock', 'receive-one');
        try {
            app(StockLedger::class)->receive($actor, $variant, 6, 'DEL-01', 'New stock', 'receive-one');
            $this->fail('A key was reused with different content.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame(8, $variant->fresh()->stock);
        }
    }

    public function test_adjustment_records_difference_and_replay_does_not_recompute_it(): void
    {
        $actor = $this->manager();
        $variant = $this->variant(3);
        $first = app(StockLedger::class)->adjustToCount($actor, $variant, 1, 'Physical count', 'count-one');
        $again = app(StockLedger::class)->adjustToCount($actor, $variant, 1, 'Physical count', 'count-one');
        $this->assertSame(-2, $first->delta);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $variant->fresh()->stock);
    }

    public function test_stale_count_form_cannot_overwrite_new_movements(): void
    {
        $actor = $this->manager();
        $variant = $this->variant(3);
        $version = $variant->version;
        app(StockLedger::class)->receive($actor, $variant, 2, 'DEL-01', 'New stock', 'receive-one');
        try {
            app(StockLedger::class)->adjustToCount($actor, $variant, 1, 'Physical count', 'count-one', $version);
            $this->fail('A stale count was applied.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame(5, $variant->fresh()->stock);
        }
    }

    public function test_negative_stock_rolls_back_movement_balance_and_audit(): void
    {
        $variant = $this->variant(1);
        $auditCount = AuditEvent::count();
        try {
            app(StockLedger::class)->post($variant, -2, 'sale', 'sale-one', $this->manager(), 'Sale deduction');
            $this->fail('Negative stock was accepted.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertSame(1, $variant->fresh()->stock);
            $this->assertSame($auditCount, AuditEvent::count());
            $this->assertSame(1, StockMovement::count());
        }
    }

    public function test_staff_cannot_receive_stock_or_open_the_form(): void
    {
        $staff = $this->staff();
        $variant = $this->variant();
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp])
            ->get('/inventory/'.$variant->id.'/stock')->assertForbidden();
        $this->postJson('/inventory/'.$variant->id.'/stock', ['type' => 'receive', 'quantity' => 2, 'reference' => 'DEL-01', 'reason' => 'New stock', 'operation_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_relative_adjustment_retry_posts_only_once(): void
    {
        $variant = $this->variant(3);
        $this->actingAs($this->manager())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $payload = ['type' => 'adjust', 'adjustment_mode' => 'increase', 'base_stock' => 3, 'quantity' => 2, 'reason' => 'Count correction', 'operation_key' => (string) Str::uuid(), 'expected_version' => $variant->version];
        $this->postJson('/inventory/'.$variant->id.'/stock', $payload)->assertOk();
        $this->postJson('/inventory/'.$variant->id.'/stock', $payload)->assertOk();
        $this->assertSame(5, $variant->fresh()->stock);
    }

    public function test_manager_stock_form_and_history_render_and_post_the_business_date(): void
    {
        $variant = $this->variant();
        $this->actingAs($this->manager())->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $this->get('/inventory/'.$variant->id.'/stock')->assertOk()->assertSee('Receive stock');
        $this->post('/inventory/'.$variant->id.'/stock', ['type' => 'receive', 'quantity' => 2, 'reference' => 'DEL-01', 'reason' => 'New stock', 'operation_key' => (string) Str::uuid(), 'date' => now('Asia/Manila')->toDateString()])->assertRedirect('/inventory/'.$variant->id);
        $movement = $variant->movements()->where('type', 'receive')->firstOrFail();
        $this->assertSame(now('Asia/Manila')->toDateString(), $movement->occurred_at->timezone('Asia/Manila')->toDateString());
        $this->get('/inventory/'.$variant->id)->assertOk()->assertSee('Stock movements')->assertSee('DEL-01');
    }
}
