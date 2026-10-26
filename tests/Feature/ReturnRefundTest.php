<?php

namespace Tests\Feature;

use App\Models\RefundRecord;
use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\Pos\ApprovalService;
use App\Services\Pos\CheckoutService;
use App\Services\Sales\RefundService;
use App\Services\Sales\ReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MotoFixtures;
use Tests\TestCase;

class ReturnRefundTest extends TestCase
{
    use MotoFixtures, RefreshDatabase;

    private function sale(int $quantity = 1, int $price = 10000): Sale
    {
        $cashier = $this->staff();
        $variant = $this->variant(5, $price);
        $cart = $this->cart($cashier, [$variant->id => $quantity])->refresh();

        return app(CheckoutService::class)->complete($cashier, $cart, 1, (string) Str::uuid(), ['method' => 'cash', 'received_centavos' => $price * $quantity])->sale;
    }

    private function rejects(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Return or refund unexpectedly succeeded.');
        } catch (AuthorizationException $error) {
            $this->assertSame(403, $status);
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_return_requests_enforce_sale_ownership_and_remaining_units_including_pending_requests(): void
    {
        $sale = $this->sale();
        $line = $sale->lines->first();
        $service = app(ReturnService::class);
        $this->rejects(403, fn () => $service->request($this->staff(), $sale, [$line->id => 1], 'Wrong owner'));
        $this->rejects(422, fn () => $service->request($sale->cashier, $sale, [$line->id => 2], 'Too many units'));
        $request = $service->request($sale->cashier, $sale, [$line->id => 1], 'Incorrect fitment');
        $this->assertSame('pending', $request->status);
        $this->rejects(409, fn () => $service->request($sale->cashier, $sale, [$line->id => 1], 'Duplicate request'));
        $this->assertSame(1, ReturnRequest::count());
        $this->assertSame(4, $line->variant->fresh()->stock);
    }

    public function test_rejection_releases_pending_quantity_and_never_changes_stock(): void
    {
        $sale = $this->sale();
        $line = $sale->lines->first();
        $service = app(ReturnService::class);
        $request = $service->request($sale->cashier, $sale, [$line->id => 1], 'Incorrect fitment');
        $service->review($this->manager(), $request, [], false, 'Item was not returned');
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
        $again = $service->request($sale->cashier, $sale, [$line->id => 1], 'Item is now available');
        $this->assertSame('pending', $again->status);
    }

    public function test_mixed_condition_return_restocks_only_sellable_units_once(): void
    {
        $sale = $this->sale(2);
        $line = $sale->lines->first();
        $service = app(ReturnService::class);
        $manager = $this->manager();
        $request = $service->request($sale->cashier, $sale, [$line->id => 2], 'One item damaged');
        $returnLine = $request->lines->first();
        $conditions = [$returnLine->id => ['restock_quantity' => 1]];
        $first = $service->review($manager, $request, $conditions, true, 'Inspected two units');
        $again = $service->review($manager, $request, $conditions, true, 'Inspected two units');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('accepted', $first->status);
        $this->assertSame('mixed', $returnLine->fresh()->condition);
        $this->assertSame(4, $line->variant->fresh()->stock);
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
        $this->assertSame(20000, $returnLine->fresh()->refundable_centavos);
        $this->assertSame(0, RefundRecord::where('status', 'paid')->count());
        $this->rejects(409, fn () => $service->review($manager, $request, [$returnLine->id => ['restock_quantity' => 2]], true, 'Inspected two units'));
    }

    public function test_competing_legacy_return_reviews_cannot_accept_the_same_sold_unit_twice(): void
    {
        $sale = $this->sale();
        $line = $sale->lines->first();
        $service = app(ReturnService::class);
        $first = $service->request($sale->cashier, $sale, [$line->id => 1], 'First request');
        $second = ReturnRequest::create(['sale_id' => $sale->id, 'requester_id' => $sale->cashier_id, 'reason' => 'Competing legacy request'])->refresh();
        $second->lines()->create(['sale_line_id' => $line->id, 'quantity' => 1]);
        $manager = $this->manager();
        $service->review($manager, $first, [$first->lines->first()->id => ['restock_quantity' => 1]], true, 'Inspected first request');
        $this->rejects(409, fn () => $service->review($manager, $second, [$second->lines->first()->id => ['restock_quantity' => 1]], true, 'Inspected second request'));
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(5, $line->variant->fresh()->stock);
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
    }

    public function test_damaged_return_acceptance_does_not_restock_and_invalid_review_rolls_back(): void
    {
        $sale = $this->sale();
        $line = $sale->lines->first();
        $service = app(ReturnService::class);
        $request = $service->request($sale->cashier, $sale, [$line->id => 1], 'Broken item returned');
        $id = $request->lines->first()->id;
        $manager = $this->manager();
        $this->rejects(422, fn () => $service->review($manager, $request, [$id => ['restock_quantity' => 2]], true, 'Invalid inspection'));
        $this->assertSame('pending', $request->fresh()->status);
        $service->review($manager, $request, [$id => ['restock_quantity' => 0]], true, 'Damaged and unsellable');
        $this->assertSame('damaged', $request->lines->first()->fresh()->condition);
        $this->assertSame(4, $line->variant->fresh()->stock);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
    }

    public function test_refund_pending_failed_paid_transitions_share_one_operation_and_paid_is_terminal(): void
    {
        $sale = $this->sale();
        $line = $sale->lines->first();
        $manager = $this->manager();
        $returns = app(ReturnService::class);
        $request = $returns->request($sale->cashier, $sale, [$line->id => 1], 'Incorrect fitment');
        $service = app(RefundService::class);
        $this->rejects(409, fn () => $service->recordOutcome($manager, $request, 'refund-one', 10000, 'pending', 'DEMO-REFUND-01'));
        $returns->review($manager, $request, [$request->lines->first()->id => ['restock_quantity' => 1]], true, 'Item inspected');
        $first = $service->recordOutcome($manager, $request, 'refund-one', 10000, 'pending', 'DEMO-REFUND-01');
        $this->rejects(409, fn () => $service->recordOutcome($manager, $request, 'refund-other', 1, 'pending', 'DEMO-REFUND-02'));
        $service->recordOutcome($manager, $request, 'refund-one', 10000, 'failed', 'DEMO-REFUND-01');
        $this->assertSame(0, RefundRecord::where('status', 'paid')->sum('amount_centavos'));
        $service->recordOutcome($manager, $request, 'refund-one', 10000, 'pending', 'DEMO-REFUND-01');
        $paid = $service->recordOutcome($manager, $request, 'refund-one', 10000, 'paid', 'DEMO-REFUND-01');
        $again = $service->recordOutcome($manager, $request, 'refund-one', 10000, 'paid', 'DEMO-REFUND-01');
        $this->assertSame($first->id, $paid->id);
        $this->assertSame($paid->id, $again->id);
        $this->assertNotNull($paid->paid_at);
        $this->assertSame(1, RefundRecord::count());
        $this->assertSame(10000, (int) RefundRecord::where('status', 'paid')->sum('amount_centavos'));
        $this->rejects(409, fn () => $service->recordOutcome($manager, $request, 'refund-one', 10000, 'failed', 'DEMO-REFUND-01'));
        $this->rejects(409, fn () => $service->recordOutcome($manager, $request, 'refund-two', 1, 'paid', 'DEMO-REFUND-02'));
    }

    public function test_penny_discount_refund_caps_reconcile_and_returned_units_do_not_wait_for_payout(): void
    {
        $cashier = $this->staff();
        $manager = $this->manager();
        $first = $this->variant(2, 100);
        $second = $this->variant(2, 100);
        $cart = $this->cart($cashier, [$first->id => 1, $second->id => 1])->refresh();
        app(ApprovalService::class)->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 1], 'Penny allocation');
        $sale = app(CheckoutService::class)->complete($cashier, $cart, 2, 'penny-sale', ['method' => 'cash', 'received_centavos' => 200])->sale;
        $returns = app(ReturnService::class);
        $request = $returns->request($cashier, $sale, $sale->lines->mapWithKeys(fn ($line) => [$line->id => 1])->all(), 'Both units returned');
        $conditions = $request->lines->mapWithKeys(fn ($line) => [$line->id => ['restock_quantity' => 1]])->all();
        $returns->review($manager, $request, $conditions, true, 'Both inspected');
        $this->assertSame([99, 100], $request->lines()->orderBy('sale_line_id')->pluck('refundable_centavos')->all());
        $this->assertSame(0, $sale->lines->sum('quantity') - $request->lines()->sum('quantity'));
        $this->assertSame(0, RefundRecord::where('status', 'paid')->count());
        $this->rejects(409, fn () => app(RefundService::class)->recordOutcome($manager, $request, 'too-large', 200, 'paid', 'DEMO-01'));
        app(RefundService::class)->recordOutcome($manager, $request, 'exact-refund', 199, 'paid', 'DEMO-01');
        $this->assertSame(199, (int) RefundRecord::where('status', 'paid')->sum('amount_centavos'));
    }

    public function test_partial_returns_of_one_discounted_line_allocate_the_remaining_cent_exactly(): void
    {
        $cashier = $this->staff();
        $manager = $this->manager();
        $variant = $this->variant(3, 100);
        $cart = $this->cart($cashier, [$variant->id => 2])->refresh();
        app(ApprovalService::class)->approve($manager, 'Unit test passphrase 2026!', $cart, 1, 'discount', ['discount_centavos' => 1], 'Penny allocation');
        $sale = app(CheckoutService::class)->complete($cashier, $cart, 2, 'partial-penny', ['method' => 'cash', 'received_centavos' => 200])->sale;
        $service = app(ReturnService::class);
        $amounts = [];
        foreach ([1, 2] as $index) {
            $request = $service->request($cashier, $sale, [$sale->lines->first()->id => 1], 'Partial return '.$index);
            $service->review($manager, $request, [$request->lines->first()->id => ['restock_quantity' => 1]], true, 'Inspected unit '.$index);
            $amounts[] = $request->lines()->first()->refundable_centavos;
        }
        $this->assertSame([99, 100], $amounts);
        $this->assertSame(3, $variant->fresh()->stock);
    }

    public function test_http_manager_review_requires_password_and_paid_outcome_requires_confirmation(): void
    {
        $sale = $this->sale();
        $staff = $sale->cashier;
        $this->actingAs($staff)->withSession(['moto_signed_in_at' => now()->timestamp, 'moto_last_activity_at' => now()->timestamp]);
        $response = $this->postJson('/sales/'.$sale->id.'/returns', ['lines' => [$sale->lines->first()->id => 1], 'reason' => 'Wrong model'])->assertCreated();
        $request = ReturnRequest::findOrFail($response->json('return_id'));
        $this->get('/returns/'.$request->id)->assertOk()->assertSee('Pending');
        $review = ['accept' => true, 'reason' => 'Inspected item', 'password' => 'Unit test passphrase 2026!', 'conditions' => [$request->lines->first()->id => ['restock_quantity' => 1]]];
        $this->postJson('/returns/'.$request->id.'/review', $review)->assertForbidden();
        $this->actingAs($this->manager());
        $this->postJson('/returns/'.$request->id.'/review', array_merge($review, ['password' => 'wrong']))->assertUnprocessable();
        $this->assertSame('pending', $request->fresh()->status);
        $this->postJson('/returns/'.$request->id.'/review', $review)->assertOk();
        $refund = ['operation_key' => (string) Str::uuid(), 'amount' => '100.00', 'status' => 'paid', 'reference' => 'DEMO-PAYOUT-01', 'password' => 'Unit test passphrase 2026!'];
        $this->postJson('/returns/'.$request->id.'/refund', $refund)->assertUnprocessable();
        $this->postJson('/returns/'.$request->id.'/refund', array_merge($refund, ['confirmed' => true]))->assertOk();
        $this->get('/returns/'.$request->id)->assertOk()->assertSee('Paid');
        $this->actingAs($staff)->postJson('/returns/'.$request->id.'/refund', array_merge($refund, ['confirmed' => true]))->assertForbidden();
    }

    public function test_partial_payout_cannot_be_duplicated_using_a_new_key_with_the_same_tracking_reference(): void
    {
        $sale = $this->sale();
        $manager = $this->manager();
        $returns = app(ReturnService::class);
        $request = $returns->request($sale->cashier, $sale, [$sale->lines->first()->id => 1], 'Item returned');
        $returns->review($manager, $request, [$request->lines->first()->id => ['restock_quantity' => 1]], true, 'Inspected item');
        $service = app(RefundService::class);
        $first = $service->recordOutcome($manager, $request, 'partial-one', 5000, 'pending', 'DEMO-PARTIAL-A');
        $this->rejects(409, fn () => $service->recordOutcome($manager, $request, 'partial-duplicate', 5000, 'pending', 'DEMO-PARTIAL-A'));
        $service->recordOutcome($manager, $request, 'partial-two', 5000, 'paid', 'DEMO-PARTIAL-B');
        $service->recordOutcome($manager, $request, 'partial-one', 5000, 'paid', 'DEMO-PARTIAL-A');
        $again = $service->recordOutcome($manager, $request, 'partial-one', 5000, 'paid', 'DEMO-PARTIAL-A');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(2, RefundRecord::count());
        $this->assertSame(10000, (int) RefundRecord::where('status', 'paid')->sum('amount_centavos'));
    }

    public function test_pending_request_blocks_another_request_for_the_same_item_until_reviewed(): void
    {
        $sale = $this->sale(2);
        $line = $sale->lines->first();
        $service = app(ReturnService::class);
        $service->request($sale->cashier, $sale, [$line->id => 1], 'First unit returned');
        $this->rejects(409, fn () => $service->request($sale->cashier, $sale, [$line->id => 1], 'Retry the first request'));
        $this->assertSame(1, ReturnRequest::count());
    }
}
