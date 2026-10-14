<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Business;
use Tests\TestCase;

/**
 * Offline synchronization: a disconnected terminal queues a sale, and pushing
 * it must create exactly one sale with one stock movement and one journal
 * entry — no matter how many times the push is retried.
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->bootBusiness();
    }

    /** @return array<string, mixed> */
    protected function queuedSale(string $key, array $overrides = []): array
    {
        $product = $overrides['product'] ?? $this->business->productInStock(50, 8.00, 12.00);
        unset($overrides['product']);

        return array_merge([
            'idempotency_key' => $key,
            'warehouse_id' => $this->business->warehouse->id,
            'cash_register_session_id' => null,
            'customer_id' => null,
            'sold_at' => now()->subMinutes(10)->toIso8601String(),
            'discount_total' => '0.00',
            'shipping_total' => '0.00',
            'notes' => 'Captured while offline',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => '2',
                'unit_price' => '12.0000',
                'discount_amount' => '0.00',
            ]],
            'payments' => [[
                'payment_method_id' => $this->business->cashMethod->id,
                'amount' => '27.60',
                'tendered_amount' => '30.00',
            ]],
        ], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('k')]])
            ->assertUnauthorized();
    }

    public function test_it_records_a_queued_offline_sale(): void
    {
        $product = $this->business->productInStock(50, 8.00, 12.00);

        $response = $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-1', ['product' => $product])]]);

        $response->assertOk();
        $response->assertJsonPath('summary.synced', 1);

        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame('offline', $sale->source);
        $this->assertSame('synced', $sale->sync_status);
        $this->assertSame('27.60', $sale->grand_total);
        $this->assertSame('48.0000', (string) $product->stocks()->first()->quantity);
    }

    public function test_pushing_the_same_queue_twice_creates_one_sale(): void
    {
        $product = $this->business->productInStock(50, 8.00, 12.00);
        $payload = ['sales' => [$this->queuedSale('offline-key-2', ['product' => $product])]];

        $this->actingAs($this->business->owner)->postJson(route('sync.receive'), $payload)->assertOk();
        $this->actingAs($this->business->owner)->postJson(route('sync.receive'), $payload)->assertOk();

        $this->assertSame(1, Sale::count(), 'A duplicate push must not create a second sale.');
        $this->assertSame('48.0000', (string) $product->stocks()->first()->quantity,
            'Stock must only be deducted once.');
        $this->assertSame(1, \App\Models\JournalEntry::count(),
            'A duplicate push must not post a second journal entry.');
    }

    public function test_a_duplicate_push_is_reported_as_skipped(): void
    {
        $payload = ['sales' => [$this->queuedSale('offline-key-3')]];

        $this->actingAs($this->business->owner)->postJson(route('sync.receive'), $payload)->assertOk();

        $second = $this->actingAs($this->business->owner)->postJson(route('sync.receive'), $payload);

        $second->assertOk();
        $second->assertJsonPath('summary.skipped', 1);
        $second->assertJsonPath('results.0.result', 'skipped');
    }

    public function test_it_writes_an_idempotency_ledger_row(): void
    {
        $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-4')]])
            ->assertOk();

        $ledger = IdempotencyKey::where('key', 'offline-key-4')
            ->where('endpoint', 'sync/sales')
            ->first();

        $this->assertNotNull($ledger);
        $this->assertSame('completed', $ledger->status);
        $this->assertSame(Sale::first()->id, $ledger->resource_id);
    }

    public function test_a_stock_conflict_is_parked_for_review_not_overwritten(): void
    {
        // The terminal believed there was stock; head office has since sold it.
        $product = $this->business->productInStock(1, 8.00, 12.00);

        $response = $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-5', [
                'product' => $product,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => '10',
                    'unit_price' => '12.0000',
                    'discount_amount' => '0.00',
                ]],
            ])]]);

        $response->assertOk();
        $response->assertJsonPath('results.0.result', 'attention');

        // Crucially: no sale was created and stock was not forced negative.
        $this->assertSame(0, Sale::count());
        $this->assertSame('1.0000', (string) $product->stocks()->first()->quantity);
    }

    public function test_a_conflicted_sale_is_recorded_as_needing_attention(): void
    {
        $product = $this->business->productInStock(1, 8.00, 12.00);

        $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-6', [
                'product' => $product,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => '10',
                    'unit_price' => '12.0000',
                    'discount_amount' => '0.00',
                ]],
            ])]])
            ->assertOk();

        $ledger = IdempotencyKey::where('key', 'offline-key-6')->where('endpoint', 'sync/sales')->first();

        $this->assertNotNull($ledger);
        $this->assertSame('attention', $ledger->status);
        $this->assertNull($ledger->resource_id);
    }

    public function test_a_batch_with_one_bad_record_still_processes_the_good_ones(): void
    {
        $good = $this->business->productInStock(50, 8.00, 12.00);
        $bad = $this->business->productInStock(1, 8.00, 12.00);

        $response = $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [
                $this->queuedSale('offline-batch-good', ['product' => $good]),
                $this->queuedSale('offline-batch-bad', [
                    'product' => $bad,
                    'items' => [[
                        'product_id' => $bad->id,
                        'quantity' => '99',
                        'unit_price' => '12.0000',
                        'discount_amount' => '0.00',
                    ]],
                ]),
            ]]);

        $response->assertOk();
        $response->assertJsonPath('summary.synced', 1);
        $response->assertJsonPath('summary.attention', 1);

        $this->assertSame(1, Sale::count(), 'The healthy sale must still be recorded.');
    }

    public function test_a_future_timestamp_is_clamped_to_now(): void
    {
        $response = $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-7', [
                'sold_at' => now()->addDays(3)->toIso8601String(),
            ])]]);

        $response->assertOk();

        $sale = Sale::first();
        $this->assertTrue($sale->sold_at->isPast(), 'A future sale date must not be trusted.');
    }

    public function test_a_payment_method_that_is_no_longer_active_is_rejected(): void
    {
        $this->business->cardMethod->update(['is_active' => false]);

        $response = $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-8', [
                'payments' => [[
                    'payment_method_id' => $this->business->cardMethod->id,
                    'amount' => '27.60',
                    'tendered_amount' => '27.60',
                ]],
            ])]]);

        $response->assertOk();
        $this->assertSame(0, Sale::count());
    }

    public function test_the_receiving_user_must_hold_the_create_sale_permission(): void
    {
        // A Cashier has pos.access (so can reach /sync) but the endpoint also
        // requires sales.create, which the Cashier role does hold — so use a
        // user with no relevant permissions at all.
        $viewer = \App\Models\User::factory()->create([
            'branch_id' => $this->business->branch->id,
        ]);

        $this->actingAs($viewer)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-9')]])
            ->assertForbidden();
    }

    public function test_the_sync_status_screen_renders_for_a_cashier(): void
    {
        $cashier = $this->business->staffWithRole('Cashier', ['email' => 'pos@example.test']);

        $this->actingAs($cashier)
            ->get(route('sync.status'))
            ->assertOk();
    }

    public function test_the_offline_sale_totals_reconcile_with_the_online_path(): void
    {
        $product = $this->business->productInStock(50, 8.00, 12.00);

        $this->actingAs($this->business->owner)
            ->postJson(route('sync.receive'), ['sales' => [$this->queuedSale('offline-key-10', ['product' => $product])]])
            ->assertOk();

        $sale = Sale::first();

        $this->assertSame('24.00', $sale->subtotal);
        $this->assertSame('3.60', $sale->tax_total);
        $this->assertSame('27.60', $sale->grand_total);
        $this->assertTrue(Money::isZero($sale->due_total));
    }
}
