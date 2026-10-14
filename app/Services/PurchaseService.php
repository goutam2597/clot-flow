<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Support\AuditLogger;
use App\Support\Money;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative service for purchase documents.
 *
 * A purchase increases inventory (on receive), creates accounts payable and,
 * on payment, records the supplier payment + journal entry. Everything runs in
 * a single transaction and is idempotent.
 */
class PurchaseService
{
    public function __construct(
        protected InventoryService $inventory,
        protected AccountingService $accounting,
    ) {}

    /**
     * @param  array  $data  validated purchase payload
     * @param  string|null  $idempotencyKey
     */
    public function create(array $data, ?string $idempotencyKey = null): Purchase
    {
        if ($idempotencyKey) {
            $existing = Purchase::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['items', 'supplier']);
            }
        }

        return DB::transaction(function () use ($data, $idempotencyKey) {
            $lines = $this->calculateTotals($data);

            $purchase = Purchase::create([
                'purchase_number' => NumberGenerator::next('PO'),
                'supplier_id' => $data['supplier_id'],
                'branch_id' => $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'],
                'user_id' => $data['user_id'],
                'subtotal' => $lines['subtotal'],
                'discount_total' => $lines['discount_total'],
                'tax_total' => $lines['tax_total'],
                'shipping_total' => $lines['shipping_total'],
                'grand_total' => $lines['grand_total'],
                'paid_total' => $lines['paid_total'],
                'due_total' => $lines['due_total'],
                'status' => $data['status'] ?? 'pending',
                'payment_status' => $lines['payment_status'],
                'idempotency_key' => $idempotencyKey,
                'purchased_at' => $data['purchased_at'] ?? now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines['items'] as $item) {
                PurchaseItem::create(array_merge(['purchase_id' => $purchase->id], $item));
            }

            // If created already "received", apply inventory + accounting.
            if ($purchase->status === 'received') {
                $this->receiveStock($purchase);
                $this->accounting->postPurchaseEntry($purchase, $idempotencyKey);
            }

            AuditLogger::log(
                'purchase.created',
                Purchase::class,
                $purchase->id,
                null,
                [
                    'purchase_number' => $purchase->purchase_number,
                    'supplier_id' => $purchase->supplier_id,
                    'warehouse_id' => $purchase->warehouse_id,
                    'subtotal' => (string) $purchase->subtotal,
                    'tax_total' => (string) $purchase->tax_total,
                    'grand_total' => (string) $purchase->grand_total,
                    'due_total' => (string) $purchase->due_total,
                    'status' => $purchase->status,
                    'payment_status' => $purchase->payment_status,
                    'items' => count($lines['items']),
                ],
                __('Purchase :number created', ['number' => $purchase->purchase_number]),
                $purchase->branch_id
            );

            return $purchase->load(['items', 'supplier']);
        });
    }

    /**
     * Recalculate every monetary value on a purchase from its line items.
     *
     * Mirrors the sale engine: an order-level discount is allocated across the
     * lines before tax so tax is charged on what is actually owed, and the
     * invariant `sum(line_total) === subtotal + tax_total` holds — the reports
     * and the offline reconciliation both depend on it.
     *
     * @param  array  $data  payload containing an `items` array
     * @return array<string, mixed>
     */
    protected function calculateTotals(array $data): array
    {
        $netTotal = '0.00';
        $lineDiscountTotal = '0.00';
        $items = [];

        // Pass 1 — line gross and net, before the document-level discount.
        foreach ($data['items'] as $item) {
            $quantity = $this->decimal($item['quantity'] ?? '0', 4);
            $unitCost = $this->decimal($item['unit_cost'] ?? '0', 4);

            $gross = Money::mul($unitCost, $quantity, 4);
            $lineDiscount = Money::min($this->decimal($item['discount_amount'] ?? '0', 2), $gross, 4);
            $net = Money::sub($gross, $lineDiscount, 4);

            $netTotal = Money::add($netTotal, $net, 2);
            $lineDiscountTotal = Money::add($lineDiscountTotal, $lineDiscount, 2);

            $items[] = [
                'product_id' => (int) $item['product_id'],
                'product_variant_id' => isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
                'tax_rate_id' => isset($item['tax_rate_id']) ? (int) $item['tax_rate_id'] : null,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'discount_amount' => $lineDiscount,
                'tax_rate' => $this->taxRateFor($item['tax_rate_id'] ?? null),
                'net_before_order_discount' => $net,
            ];
        }

        // Pass 2 — allocate the order discount, then tax each line on its net.
        $orderDiscount = Money::min($this->decimal($data['discount_total'] ?? '0'), $netTotal, 2);
        $shipping = $this->decimal($data['shipping_total'] ?? '0');

        $subtotal = '0.00';
        $taxTotal = '0.00';
        $running = '0.00';
        $lastIndex = array_key_last($items);

        foreach ($items as $index => $line) {
            // The final line absorbs the rounding residue so the allocated shares
            // always sum back to the order discount exactly.
            $share = $index === $lastIndex
                ? Money::sub($orderDiscount, $running, 2)
                : Money::round(Money::mul($line['net_before_order_discount'], $orderDiscount, 6), 2);

            $running = Money::add($running, $share, 2);

            $net = Money::sub($line['net_before_order_discount'], $share, 2);
            $tax = Money::percent($net, $line['tax_rate'], 2);

            $subtotal = Money::add($subtotal, $net, 2);
            $taxTotal = Money::add($taxTotal, $tax, 2);

            unset($line['net_before_order_discount'], $line['tax_rate']);

            $items[$index] = array_merge($line, [
                'order_discount_share' => $share,
                'tax_amount' => $tax,
                'line_total' => Money::add($net, $tax, 2),
            ]);
        }

        $grandTotal = Money::add(Money::add($subtotal, $taxTotal, 2), $shipping, 2);
        $paid = Money::min($this->decimal($data['paid_total'] ?? '0'), $grandTotal, 2);
        $due = Money::sub($grandTotal, $paid, 2);

        if (Money::isNegative($due)) {
            $due = '0.00';
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'discount_total' => Money::add($lineDiscountTotal, $orderDiscount, 2),
            'tax_total' => $taxTotal,
            'shipping_total' => $shipping,
            'grand_total' => $grandTotal,
            'paid_total' => $paid,
            'due_total' => $due,
            'payment_status' => Money::isZero($due) ? 'paid' : (Money::isZero($paid) ? 'due' : 'partial'),
        ];
    }

    /**
     * Resolve a tax rate percentage from its id, defaulting to zero.
     */
    protected function taxRateFor($id): string
    {
        if (! $id) {
            return '0';
        }

        static $cache = [];

        if (! array_key_exists($id, $cache)) {
            $cache[$id] = (string) (\App\Models\TaxRate::where('id', $id)->value('rate') ?? '0');
        }

        return $cache[$id];
    }

    /**
     * Normalise an input value into a clean decimal string.
     */
    protected function decimal($value, int $scale = 2): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || ! is_numeric($value)) {
            $value = '0';
        }

        return bcadd($value, '0', $scale);
    }

    /**
     * Receive (all or partial) a purchase: increase inventory once and post AP.
     */
    public function receive(Purchase $purchase): Purchase
    {
        if ($purchase->status === 'received') {
            return $purchase; // idempotent — nothing to do
        }

        return DB::transaction(function () use ($purchase) {
            $this->receiveStock($purchase);
            $purchase->update(['status' => 'received']);
            $this->accounting->postPurchaseEntry($purchase);

            AuditLogger::log(
                'purchase.received',
                Purchase::class,
                $purchase->id,
                ['status' => $purchase->status],
                ['status' => 'received'],
                __('Purchase :number received into stock', ['number' => $purchase->purchase_number]),
                $purchase->branch_id
            );

            return $purchase->load(['items', 'supplier']);
        });
    }

    /**
     * Record a supplier payment against a purchase.
     *
     * Posts the double-entry settlement (Dr Accounts Payable / Cr Cash) in the
     * same transaction that updates the document, so the payable in the ledger
     * and the balance on the purchase can never disagree. Overpaying is capped
     * at the outstanding amount rather than silently creating a credit.
     */
    public function pay(
        Purchase $purchase,
        string $amount,
        int $paymentMethodId,
        ?string $reference = null,
        ?\App\Models\PaymentMethod $method = null
    ): Purchase {
        $method ??= \App\Models\PaymentMethod::find($paymentMethodId);

        return DB::transaction(function () use ($purchase, $amount, $method, $paymentMethodId, $reference) {
            $amount = Money::min($this->decimal($amount), (string) $purchase->due_total, 2);

            if (Money::isZero($amount)) {
                return $purchase->load(['items', 'supplier']);
            }

            $paid = Money::add((string) $purchase->paid_total, $amount, 2);
            $due = Money::sub((string) $purchase->grand_total, $paid, 2);

            if (Money::isNegative($due)) {
                $due = '0.00';
            }

            $purchase->update([
                'paid_total' => $paid,
                'due_total' => $due,
                'payment_status' => Money::isZero($due) ? 'paid' : 'partial',
            ]);

            $lines = [
                [
                    'account_id' => \App\Models\Account::where('code', '2000')->value('id'),
                    'debit' => $amount,
                    'credit' => '0',
                    'description' => __('Payable to :supplier', [
                        'supplier' => $purchase->supplier?->name ?? '—',
                    ]),
                ],
                [
                    'account_id' => \App\Models\Account::where('code', '1000')->value('id'),
                    'debit' => '0',
                    'credit' => $amount,
                    'description' => __('Supplier payment via :method', [
                        'method' => $method?->name ?? __('Cash'),
                    ]),
                ],
            ];

            $this->accounting->postEntry(
                $purchase->purchased_at->toDateString(),
                $lines,
                __('Payment for purchase :number', ['number' => $purchase->purchase_number]),
                Purchase::class,
                $purchase->id,
                $purchase->branch_id,
                auth()->id(),
                // One settlement per (purchase, amount, method, reference) so a
                // retried request cannot double-pay a supplier.
                $reference
                    ? 'purchase-pay-' . md5($purchase->id . '|' . $amount . '|' . $paymentMethodId . '|' . $reference)
                    : null
            );

            \App\Support\AuditLogger::log(
                'purchase.paid',
                Purchase::class,
                $purchase->id,
                ['paid_total' => (string) $purchase->paid_total, 'due_total' => (string) $purchase->due_total],
                [
                    'paid_total' => $paid,
                    'due_total' => $due,
                    'amount' => $amount,
                    'payment_method_id' => $paymentMethodId,
                    'reference' => $reference,
                ],
                __('Paid :amount against purchase :number', [
                    'amount' => $amount,
                    'number' => $purchase->purchase_number,
                ]),
                $purchase->branch_id
            );

            return $purchase->load(['items', 'supplier']);
        });
    }

    protected function receiveStock(Purchase $purchase): void
    {
        foreach ($purchase->items as $item) {
            $qty = (string) $item->quantity;
            if (Money::isZero($qty)) {
                continue;
            }
            $this->inventory->increase(
                $purchase->warehouse_id,
                $item->product_id,
                $item->product_variant_id,
                $qty,
                (string) $item->unit_cost,
                'purchase',
                $purchase,
                $purchase->user_id,
            );
            $item->update(['received_quantity' => $qty]);
        }
    }
}
