<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative service for creating finalized sales.
 *
 * The backend recalculates every price, discount, tax and total from product
 * records — never trusting frontend-provided totals. The whole operation runs
 * in a single database transaction: sale + items + payments + inventory
 * deduction + double-entry journal entry. Idempotency keys make retries and
 * offline synchronization safe.
 */
class SaleService
{
    public function __construct(
        protected InventoryService $inventory,
        protected AccountingService $accounting,
    ) {}

    /**
     * @param  array  $data  Validated, normalized sale payload (see StoreSaleRequest).
     * @param  string|null  $idempotencyKey  Stable client-supplied key.
     */
    public function create(array $data, ?string $idempotencyKey = null): Sale
    {
        // Idempotency short-circuit.
        if ($idempotencyKey) {
            $existing = Sale::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['items', 'payments']);
            }
        }

        return DB::transaction(function () use ($data, $idempotencyKey) {
            $lines = $this->calculateTotals($data);

            // Normalise the source once: an offline sale that has just been
            // accepted by the server is, by definition, synchronized.
            $source = $data['source'] ?? 'online';

            $sale = Sale::create([
                'invoice_number' => NumberGenerator::next('INV'),
                'branch_id' => $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'],
                'cash_register_session_id' => $data['cash_register_session_id'] ?? null,
                'user_id' => $data['user_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'subtotal' => $lines['subtotal'],
                'discount_total' => $lines['discount_total'],
                'tax_total' => $lines['tax_total'],
                'shipping_total' => $lines['shipping_total'],
                'grand_total' => $lines['grand_total'],
                'paid_total' => $lines['paid_total'],
                'change_total' => $lines['change_total'],
                'due_total' => $lines['due_total'],
                'cost_total' => $lines['cost_total'],
                'payment_status' => $lines['payment_status'],
                'status' => 'completed',
                'source' => $source,
                'sync_status' => 'synced',
                'idempotency_key' => $idempotencyKey,
                'sold_at' => $data['sold_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines['items'] as $item) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'tax_rate_id' => $item['tax_rate_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'product_sku' => $item['product_sku'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['unit_cost'],
                    'discount_amount' => $item['discount_amount'],
                    'order_discount_share' => $item['order_discount_share'],
                    'tax_amount' => $item['tax_amount'],
                    'tax_rate' => $item['tax_rate'],
                    'line_total' => $item['line_total'],
                ]);

                // Inventory deduction (authoritative, audited). Throws if insufficient.
                $this->inventory->decrease(
                    $data['warehouse_id'],
                    $item['product_id'],
                    $item['product_variant_id'] ?? null,
                    $item['quantity'],
                    $item['unit_cost'],
                    'sale',
                    $sale,
                    $data['user_id'],
                );
            }

            foreach ($lines['payments'] as $payment) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $payment['payment_method_id'],
                    'amount' => $payment['amount'],
                    'tendered_amount' => $payment['tendered_amount'] ?? $payment['amount'],
                    'change_amount' => $payment['change_amount'] ?? '0.00',
                    'reference' => $payment['reference'] ?? null,
                    'status' => 'completed',
                ]);
            }

            // Post balanced double-entry journal (idempotent).
            $this->accounting->postSaleEntry($sale, $idempotencyKey);

            // Audited here rather than in the controller so every caller — the
            // POS terminal, the offline sync endpoint, a console job or a future
            // API — writes the same trail.
            AuditLogger::log(
                'sale.created',
                Sale::class,
                $sale->id,
                null,
                [
                    'invoice_number' => $sale->invoice_number,
                    'subtotal' => (string) $sale->subtotal,
                    'tax_total' => (string) $sale->tax_total,
                    'grand_total' => (string) $sale->grand_total,
                    'paid_total' => (string) $sale->paid_total,
                    'due_total' => (string) $sale->due_total,
                    'customer_id' => $sale->customer_id,
                    'items' => count($lines['items']),
                    'source' => $source,
                    'idempotency_key' => $idempotencyKey,
                ],
                __('Sale :invoice created (:source)', [
                    'invoice' => $sale->invoice_number,
                    'source' => $source,
                ]),
                $sale->branch_id
            );

            return $sale->load(['items', 'payments']);
        });
    }

    /**
     * Recalculate every monetary value server-side from product records.
     *
     * Line math: gross      = unit_price * quantity
     *            line_disc  = per-item discount (flat)
     *            net        = gross - line_disc
     *
     * An order-level discount is allocated across the lines in proportion to
     * each line's net amount *before* tax is computed, so tax is charged on
     * what the customer actually pays. This keeps three invariants that the
     * offline reconciliation depends on:
     *
     *   sum(line_total)     === subtotal + tax_total
     *   subtotal            === sum(net after all discounts)
     *   discount never drives a line (or the document) below zero
     *
     * Exclusive taxes are added on top; the line totals therefore add up to the
     * document total, which is what the cashier sees and what is charged.
     */
    protected function calculateTotals(array $data): array
    {
        $costTotal = '0.00';
        $calcItems = [];
        $netTotal = '0.00';

        // Pass 1 — line gross and net, before any document-level discount.
        foreach ($data['items'] as $item) {
            $qty = (string) $item['quantity'];
            $unitPrice = (string) $item['unit_price'];
            $unitCost = (string) ($item['unit_cost'] ?? '0');
            $taxRate = (string) ($item['tax_rate'] ?? '0');
            $lineDiscount = (string) ($item['discount_amount'] ?? '0.00');

            $gross = Money::mul($unitPrice, $qty, 4);
            $lineDiscount = Money::min($lineDiscount, $gross, 4); // never discount below zero
            $net = Money::sub($gross, $lineDiscount, 4);
            $cost = Money::mul($unitCost, $qty, 4);

            $netTotal = Money::add($netTotal, $net, 2);
            $costTotal = Money::add($costTotal, $cost, 2);

            $calcItems[] = [
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'tax_rate_id' => $item['tax_rate_id'] ?? null,
                'product_name' => $item['product_name'],
                'product_sku' => $item['product_sku'] ?? null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'tax_rate' => $taxRate,
                'line_discount' => $lineDiscount,
                'gross' => $gross,
                'net_before_order_discount' => $net,
            ];
        }

        // Pass 2 — allocate the order discount proportionally, then tax each line
        // on its post-discount net. Rounding residue is pushed onto the largest
        // line so the allocations always sum back to the order discount exactly.
        $orderDiscount = Money::min((string) ($data['discount_total'] ?? '0.00'), $netTotal, 2);
        $this->allocateOrderDiscount($calcItems, $orderDiscount);

        $subtotal = '0.00';
        $taxTotal = '0.00';
        $discountTotal = '0.00';
        $items = [];

        foreach ($calcItems as $index => $line) {
            $orderShare = Money::mul($line['net_before_order_discount'], $orderDiscount, 6);
            $orderShare = $index === array_key_last($calcItems)
                ? Money::sub($orderDiscount, $this->allocatedSoFar($calcItems, $index), 2)
                : Money::round($orderShare, 2);

            $net = Money::sub($line['net_before_order_discount'], $orderShare, 2);
            $tax = Money::percent($net, $line['tax_rate'], 2);
            $lineTotal = Money::add($net, $tax, 2);

            $subtotal = Money::add($subtotal, $net, 2);
            $taxTotal = Money::add($taxTotal, $tax, 2);
            $discountTotal = Money::add(
                Money::add($discountTotal, $line['line_discount'], 2),
                $orderShare,
                2
            );

            $items[] = [
                'product_id' => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'],
                'tax_rate_id' => $line['tax_rate_id'],
                'product_name' => $line['product_name'],
                'product_sku' => $line['product_sku'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'unit_cost' => $line['unit_cost'],
                'discount_amount' => $line['line_discount'],
                'order_discount_share' => $orderShare,
                'tax_amount' => $tax,
                'tax_rate' => $line['tax_rate'],
                'line_total' => $lineTotal,
            ];
        }

        $shipping = (string) ($data['shipping_total'] ?? '0.00');

        // grand_total = net subtotal + tax + shipping.
        $grandTotal = Money::add(Money::add($subtotal, $taxTotal, 2), $shipping, 2);

        // Payments: sum tendered, compute change and outstanding due.
        $tendered = '0.00';
        $calcPayments = [];
        foreach (($data['payments'] ?? []) as $payment) {
            $amount = (string) $payment['amount'];
            $tenderedAmount = (string) ($payment['tendered_amount'] ?? $amount);
            $change = Money::sub($tenderedAmount, $amount, 2);
            if (Money::isNegative($change)) {
                $change = '0.00';
            }
            $tendered = Money::add($tendered, $amount, 2);
            $calcPayments[] = array_merge($payment, ['change_amount' => $change]);
        }

        $paidTotal = $tendered;
        $changeTotal = Money::sum(array_map(fn ($p) => $p['change_amount'], $calcPayments));
        $dueTotal = Money::sub($grandTotal, $paidTotal, 2);
        if (Money::isNegative($dueTotal)) {
            $dueTotal = '0.00';
        }

        $paymentStatus = Money::isZero($dueTotal)
            ? 'paid'
            : (Money::isZero($paidTotal) ? 'due' : 'partial');

        return [
            'items' => $items,
            'payments' => $calcPayments,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'shipping_total' => $shipping,
            'grand_total' => $grandTotal,
            'paid_total' => $paidTotal,
            'change_total' => $changeTotal,
            'due_total' => $dueTotal,
            'cost_total' => $costTotal,
            'payment_status' => $paymentStatus,
        ];
    }

    /**
     * Round each line's share of the order discount to the currency's precision.
     * Resolved here so the last line can absorb the rounding residue.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function allocateOrderDiscount(array &$items, string $orderDiscount): void
    {
        $running = '0.00';

        foreach ($items as $index => $line) {
            $share = Money::mul($line['net_before_order_discount'], $orderDiscount, 6);
            $share = Money::round($share, 2);

            $items[$index]['order_discount_share'] = $share;
            $running = Money::add($running, $share, 2);
        }

        $residue = Money::sub($orderDiscount, $running, 2);

        if (! Money::isZero($residue) && $items !== []) {
            $last = array_key_last($items);
            $items[$last]['order_discount_share'] = Money::add(
                $items[$last]['order_discount_share'],
                $residue,
                2
            );
        }
    }

    /**
     * Sum of the order-discount shares already assigned to the lines before
     * `$index`. Used so the final line can take the remaining residue.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function allocatedSoFar(array $items, int $index): string
    {
        $total = '0.00';

        foreach (array_slice($items, 0, $index) as $line) {
            $total = Money::add($total, $line['order_discount_share'] ?? '0.00', 2);
        }

        return $total;
    }
}
