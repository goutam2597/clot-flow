<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Business;
use Tests\TestCase;

/**
 * The sale engine: authoritative pricing, stock deduction, balanced journal
 * entries and idempotent retries.
 */
class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = $this->bootBusiness();
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = [], array $itemOverrides = []): array
    {
        $product = $overrides['product'] ?? $this->business->productInStock(100, 8.00, 12.00);
        unset($overrides['product']);

        return array_merge([
            'branch_id' => $this->business->branch->id,
            'warehouse_id' => $this->business->warehouse->id,
            'user_id' => $this->business->owner->id,
            'customer_id' => null,
            'items' => [array_merge([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'quantity' => '2',
                'unit_price' => '12.0000',
                'unit_cost' => '8.0000',
                'tax_rate_id' => $this->business->taxRate->id,
                'tax_rate' => '15',
                'discount_amount' => '0.00',
            ], $itemOverrides)],
            'payments' => [[
                'payment_method_id' => $this->business->cashMethod->id,
                'amount' => '27.60',
                'tendered_amount' => '30.00',
            ]],
        ], $overrides);
    }

    public function test_it_creates_a_sale_with_server_calculated_totals(): void
    {
        $sale = app(SaleService::class)->create($this->payload(), 'test-key-0001');

        // 2 x 12.00 = 24.00 net, +15% tax = 3.60, grand total 27.60.
        $this->assertSame('24.00', $sale->subtotal);
        $this->assertSame('3.60', $sale->tax_total);
        $this->assertSame('27.60', $sale->grand_total);
        $this->assertSame('27.60', $sale->paid_total);
        $this->assertSame('0.00', $sale->due_total);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('completed', $sale->status);
        $this->assertNotEmpty($sale->invoice_number);
    }

    public function test_it_ignores_a_client_supplied_total_and_recalculates(): void
    {
        // A tampered payload claiming a grand total of 1.00 must be ignored.
        $sale = app(SaleService::class)->create(
            $this->payload(['grand_total' => '1.00', 'subtotal' => '0.01']),
            'test-key-tampered'
        );

        $this->assertSame('27.60', $sale->grand_total);
        $this->assertSame('24.00', $sale->subtotal);
    }

    public function test_it_deducts_stock_and_writes_an_auditable_movement(): void
    {
        $product = $this->business->productInStock(100, 8.00, 12.00);

        app(SaleService::class)->create($this->payload(['product' => $product]), 'test-key-stock');

        $this->assertSame('98.0000', (string) $product->stocks()->first()->quantity);

        $movement = $product->stockMovements()->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame('-2.0000', (string) $movement->quantity);
        $this->assertSame('98.0000', (string) $movement->balance_after);
        $this->assertSame('sale', $movement->type);
    }

    public function test_it_refuses_to_sell_more_than_is_in_stock(): void
    {
        $product = $this->business->productInStock(1, 8.00, 12.00);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        app(SaleService::class)->create(
            $this->payload(['product' => $product], ['quantity' => '5']),
            'test-key-nostock'
        );
    }

    public function test_a_failed_sale_leaves_no_partial_stock_or_ledger_records(): void
    {
        $product = $this->business->productInStock(1, 8.00, 12.00);

        try {
            app(SaleService::class)->create(
                $this->payload(['product' => $product], ['quantity' => '5']),
                'test-key-rollback'
            );
            $this->fail('Expected the sale to be rejected.');
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertSame(0, Sale::count(), 'The sale must not persist.');
        $this->assertSame('1.0000', (string) $product->stocks()->first()->quantity);
        $this->assertSame(0, $product->stockMovements()->count());
        $this->assertSame(0, \App\Models\JournalEntry::count());
    }

    public function test_it_posts_a_balanced_double_entry_journal(): void
    {
        $sale = app(SaleService::class)->create($this->payload(), 'test-key-journal');

        $entry = \App\Models\JournalEntry::where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertTrue($entry->isBalanced(), 'Journal entries must balance.');

        $debits = Money::sum($entry->lines->map(fn ($l) => (string) $l->debit)->all());
        $credits = Money::sum($entry->lines->map(fn ($l) => (string) $l->credit)->all());

        $this->assertSame($debits, $credits);

        // Two sides are posted: the customer settlement
        //   Dr Cash 27.60 / Cr Revenue 24.00 / Cr Tax 3.60
        // and the cost relief
        //   Dr COGS 16.00 / Cr Inventory 16.00
        $this->assertSame('43.60', $debits);

        $byCode = $entry->lines->load('account')
            ->groupBy(fn ($l) => $l->account?->code)
            ->map(fn ($group, $code) => [
                'debit' => Money::sum($group->map(fn ($l) => (string) $l->debit)->all()),
                'credit' => Money::sum($group->map(fn ($l) => (string) $l->credit)->all()),
            ]);

        $this->assertSame('27.60', $byCode['1000']['debit'], 'Cash is debited the grand total.');
        $this->assertSame('24.00', $byCode['4000']['credit'], 'Revenue is credited the net subtotal.');
        $this->assertSame('3.60', $byCode['2100']['credit'], 'Output tax is credited separately.');
        $this->assertSame('16.00', $byCode['5000']['debit'], 'Cost of goods sold is debited.');
        $this->assertSame('16.00', $byCode['1300']['credit'], 'Inventory is credited the cost relieved.');
    }

    public function test_line_totals_always_add_up_to_the_document_totals(): void
    {
        // The offline reconciliation checks exactly this invariant, so it must
        // hold for every combination of discounts and tax.
        $cases = [
            ['quantity' => '3', 'discount_amount' => '0.00'],
            ['quantity' => '3', 'discount_amount' => '5.55'],
            ['quantity' => '1', 'discount_amount' => '0.01'],
        ];

        $orderDiscounts = ['0.00', '1.11', '7.77', '36.00'];

        foreach ($cases as $caseIndex => $case) {
            foreach ($orderDiscounts as $orderIndex => $orderDiscount) {
                $sale = app(SaleService::class)->create(
                    $this->payload(['discount_total' => $orderDiscount], $case),
                    sprintf('inv-%d-%d', $caseIndex, $orderIndex)
                );

                $lineSum = Money::sum($sale->items->map(fn ($i) => (string) $i->line_total)->all());
                $expected = Money::add($sale->subtotal, $sale->tax_total, 2);

                $this->assertSame(
                    $expected,
                    $lineSum,
                    sprintf('Line totals must equal subtotal + tax (case %d/%d, discount %s).', $caseIndex, $orderIndex, $orderDiscount)
                );

                $documentTotal = Money::add(
                    Money::add($lineSum, (string) $sale->shipping_total, 2),
                    '0',
                    2
                );

                $this->assertSame($sale->grand_total, $documentTotal);
            }
        }
    }

    public function test_an_order_discount_reduces_the_taxable_amount(): void
    {
        $sale = app(SaleService::class)->create(
            $this->payload(['discount_total' => '4.00']),
            'test-key-order-discount-tax'
        );

        // 24.00 gross - 4.00 order discount = 20.00 taxable, +15% = 3.00 tax.
        $this->assertSame('20.00', $sale->subtotal);
        $this->assertSame('4.00', $sale->discount_total);
        $this->assertSame('3.00', $sale->tax_total);
        $this->assertSame('23.00', $sale->grand_total);
    }

    public function test_an_order_discount_is_split_across_lines_without_losing_cents(): void
    {
        $product = $this->business->productInStock(100, 8.00, 12.00);

        $sale = app(SaleService::class)->create([
            'branch_id' => $this->business->branch->id,
            'warehouse_id' => $this->business->warehouse->id,
            'user_id' => $this->business->owner->id,
            'discount_total' => '0.10',
            'items' => [
                ['product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku,
                    'quantity' => '1', 'unit_price' => '3.33', 'unit_cost' => '1.00', 'tax_rate' => '15', 'tax_rate_id' => $this->business->taxRate->id],
                ['product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku,
                    'quantity' => '1', 'unit_price' => '3.33', 'unit_cost' => '1.00', 'tax_rate' => '15', 'tax_rate_id' => $this->business->taxRate->id],
                ['product_id' => $product->id, 'product_name' => $product->name, 'product_sku' => $product->sku,
                    'quantity' => '1', 'unit_price' => '3.34', 'unit_cost' => '1.00', 'tax_rate' => '15', 'tax_rate_id' => $this->business->taxRate->id],
            ],
            'payments' => [['payment_method_id' => $this->business->cashMethod->id, 'amount' => '10.00']],
        ], 'test-key-split-discount');

        $allocated = Money::sum($sale->items->map(
            fn ($i) => Money::add((string) $i->discount_amount, (string) ($i->order_discount_share ?? '0.00'), 2)
        )->all());

        $this->assertSame('0.10', $allocated, 'The whole order discount must be allocated, to the cent.');
    }

    public function test_an_idempotency_key_returns_the_original_sale_instead_of_duplicating(): void
    {
        $product = $this->business->productInStock(100, 8.00, 12.00);
        $service = app(SaleService::class);

        $first = $service->create($this->payload(['product' => $product]), 'same-key-abc');
        $second = $service->create($this->payload(['product' => $product]), 'same-key-abc');

        $this->assertSame($first->id, $second->id, 'A retried key must resolve to the same sale.');
        $this->assertSame(1, Sale::count());
        $this->assertSame('98.0000', (string) $product->stocks()->first()->quantity,
            'Stock must only be deducted once.');
        $this->assertSame(1, \App\Models\JournalEntry::count(),
            'A retry must not post a second journal entry.');
    }

    public function test_line_discounts_reduce_the_taxable_amount(): void
    {
        $sale = app(SaleService::class)->create(
            $this->payload([], ['quantity' => '2', 'discount_amount' => '4.00']),
            'test-key-discount'
        );

        // 24.00 gross - 4.00 discount = 20.00 net, +3.00 tax = 23.00 total.
        $this->assertSame('20.00', $sale->subtotal);
        $this->assertSame('4.00', $sale->discount_total);
        $this->assertSame('3.00', $sale->tax_total);
        $this->assertSame('23.00', $sale->grand_total);
    }

    public function test_an_order_discount_is_applied_at_document_level(): void
    {
        $sale = app(SaleService::class)->create(
            $this->payload(['discount_total' => '4.00']),
            'test-key-order-discount'
        );

        $this->assertSame('20.00', $sale->subtotal);
        $this->assertSame('4.00', $sale->discount_total);
        $this->assertSame('23.00', $sale->grand_total);
    }

    public function test_a_discount_can_never_push_a_total_below_zero(): void
    {
        $sale = app(SaleService::class)->create(
            $this->payload(['discount_total' => '9999.00']),
            'test-key-huge-discount'
        );

        $this->assertTrue(Money::cmp($sale->grand_total, '0') >= 0);
        $this->assertSame('0.00', $sale->subtotal);
    }

    public function test_a_partially_paid_sale_is_reported_as_partial(): void
    {
        $sale = app(SaleService::class)->create(
            $this->payload(['payments' => [[
                'payment_method_id' => $this->business->cashMethod->id,
                'amount' => '10.00',
                'tendered_amount' => '10.00',
            ]]]),
            'test-key-partial'
        );

        $this->assertSame('10.00', $sale->paid_total);
        $this->assertSame('17.60', $sale->due_total);
        $this->assertSame('partial', $sale->payment_status);
    }

    public function test_split_payments_are_recorded_individually(): void
    {
        $sale = app(SaleService::class)->create(
            $this->payload(['payments' => [
                ['payment_method_id' => $this->business->cashMethod->id, 'amount' => '7.60', 'tendered_amount' => '10.00'],
                ['payment_method_id' => $this->business->cardMethod->id, 'amount' => '20.00', 'tendered_amount' => '20.00', 'reference' => 'AUTH-99'],
            ]]),
            'test-key-split'
        );

        $this->assertSame(2, $sale->payments()->count());
        $this->assertSame('27.60', $sale->paid_total);
        $this->assertSame('0.00', $sale->due_total);
        $this->assertSame('2.40', $sale->change_total, 'Change is only given on cash.');
    }

    public function test_attaching_a_customer_is_preserved(): void
    {
        $customer = Customer::factory()->create();

        $sale = app(SaleService::class)->create(
            $this->payload(['customer_id' => $customer->id]),
            'test-key-customer'
        );

        $this->assertSame($customer->id, $sale->customer_id);
    }
}
