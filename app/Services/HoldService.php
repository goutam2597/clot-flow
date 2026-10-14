<?php

namespace App\Services;

use App\Models\HeldSale;
use App\Models\Product;
use App\Models\Stock;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parking and resuming carts.
 *
 * The rule that shapes this whole class: a hold is a SNAPSHOT, not a sale. It
 * moves no stock, posts no journal entry and creates no invoice. Nothing here
 * touches inventory — that happens only when the resumed cart is actually
 * charged, through the same SaleService call as any other sale.
 *
 * The second rule: nothing is trusted on resume. Lines are re-priced from the
 * live product record, because a cart parked at 09:00 and charged at 15:00 must
 * not honour a price that was superseded in between, and a product that has
 * since been deactivated must come back as an error rather than as a sale.
 */
class HoldService
{
    /**
     * Park a cart.
     *
     * The payload is stored exactly as given. The server records a cached
     * total purely so the hold list can show something without re-pricing on
     * every render — it is display-only and is recomputed on resume.
     *
     * @param  array  $cart  { lines: [{product_id, quantity, discount_amount}], customer_id, customer_name, discount_total, note }
     */
    public function park(array $cart, array $context): HeldSale
    {
        $lines = $cart['lines'] ?? [];

        if ($lines === []) {
            throw new \InvalidArgumentException('An empty cart cannot be held.');
        }

        $products = $this->resolveProducts($lines);

        // Reject a park that could never be resumed, rather than parking
        // something that fails confusingly ten minutes later.
        foreach ($lines as $line) {
            $product = $products[$line['product_id']] ?? null;

            if (! $product) {
                throw new \InvalidArgumentException('A product in this sale no longer exists.');
            }

            if ($product->status !== 'active') {
                throw new \InvalidArgumentException(
                    sprintf('"%s" is no longer active and cannot be held.', $product->name)
                );
            }
        }

        $label = trim((string) ($cart['label'] ?? ''));

        if ($label === '') {
            $label = 'Hold — '.now()->format('H:i');
        }

        $total = $this->previewTotal($lines, $cart['discount_total'] ?? 0, $products);

        return DB::transaction(function () use ($cart, $context, $label, $lines, $total) {
            $hold = HeldSale::create([
                'reference' => (string) Str::uuid(),
                'branch_id' => $context['branch_id'] ?? null,
                'warehouse_id' => $context['warehouse_id'] ?? null,
                'cash_register_session_id' => $context['cash_register_session_id'] ?? null,
                'customer_id' => $cart['customer_id'] ?? null,
                'user_id' => $context['user_id'] ?? null,
                'label' => Str::limit($label, 120, ''),
                'payload' => [
                    'lines' => array_map(fn ($line) => [
                        'product_id' => (int) $line['product_id'],
                        'name' => $line['name'] ?? null,
                        'quantity' => (string) $line['quantity'],
                        'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
                        'discount_amount' => Money::round($line['discount_amount'] ?? '0', 2),
                    ], $lines),
                    'customer_id' => $cart['customer_id'] ?? null,
                    'customer_name' => $cart['customer_name'] ?? null,
                    'discount_total' => Money::round($cart['discount_total'] ?? '0', 2),
                    'shipping_total' => Money::round($cart['shipping_total'] ?? '0', 2),
                    'note' => $cart['note'] ?? null,
                ],
                'line_count' => count($lines),
                'cached_total' => $total,
                'parked_at' => now(),
            ]);

            AuditLogger::log(
                'held.parked',
                HeldSale::class,
                $hold->id,
                null,
                ['label' => $hold->label, 'lines' => count($lines), 'total' => $hold->cached_total],
                'Sale parked on hold: '.$hold->label,
                $context['branch_id'] ?? null
            );

            return $hold;
        });
    }

    /**
     * Holds still waiting on a branch, newest first.
     */
    public function listLive(?int $branchId): array
    {
        return HeldSale::query()
            ->live()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['customer', 'user'])
            ->orderByDesc('parked_at')
            ->get()
            ->map(fn (HeldSale $hold) => [
                'reference' => $hold->reference,
                'label' => $hold->label,
                'parked_at' => $hold->parked_at?->toIso8601String(),
                'parked_at_label' => $hold->parked_at?->format('H:i'),
                'lines' => $hold->line_count,
                'total' => $hold->cached_total,
                'customer' => $hold->customer?->name ?? $hold->payload['customer_name'] ?? null,
                'mine' => $hold->user_id !== null && $hold->user_id === auth()->id(),
            ])
            ->all();
    }

    /**
     * Take a hold back onto the till.
     *
     * Re-prices every line from the product record and refuses anything that
     * cannot be sold. Returns the cart shape pos.js already understands, so
     * resuming is a state assignment rather than a translation layer.
     *
     * @throws \RuntimeException when a line can no longer be sold.
     */
    public function resume(HeldSale $hold): array
    {
        $lines = $hold->payload['lines'] ?? [];
        $products = $this->resolveProducts($lines);

        $unavailable = [];

        foreach ($lines as $line) {
            $product = $products[$line['product_id']] ?? null;

            if (! $product || $product->status !== 'active') {
                $unavailable[] = $line['name'] ?? ('product #'.$line['product_id']);
            }
        }

        if ($unavailable !== []) {
            throw new \RuntimeException(
                'No longer available: '.implode(', ', array_slice($unavailable, 0, 3))
            );
        }

        // Re-price. The stored unit_price is only ever a preview.
        $out = [];
        $available = $this->availability($hold->warehouse_id, array_keys($products));

        foreach ($lines as $line) {
            $product = $products[$line['product_id']];

            $out[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => Money::round($product->selling_price, 2),
                'cost' => Money::round($product->cost_price, 2),
                'tax_rate' => Money::round($product->taxRate?->rate ?? '0', 4),
                'tax_rate_id' => $product->tax_rate_id,
                'available' => $available[$product->id] ?? '0.0000',
                'track_stock' => (bool) $product->track_stock,
                'allow_negative_stock' => (bool) $product->allow_negative_stock,
                'unit' => $product->unit?->name,
                'image_url' => $product->image_url,
                'quantity' => (string) $line['quantity'],
                'discount' => Money::round($line['discount_amount'] ?? '0', 2),
            ];
        }

        $hold->forceFill(['resumed_at' => now()])->save();

        AuditLogger::log(
            'held.resumed',
            HeldSale::class,
            $hold->id,
            null,
            ['label' => $hold->label],
            'Held sale resumed: '.$hold->label,
            $hold->branch_id
        );

        return [
            'lines' => $out,
            'customer_id' => $hold->payload['customer_id'] ?? null,
            'customer_name' => $hold->payload['customer_name'] ?? null,
            'discount_total' => $hold->payload['discount_total'] ?? '0.00',
            'note' => $hold->payload['note'] ?? null,
        ];
    }

    /**
     * Discard a hold.
     *
     * Kept, not deleted: "the cashier threw this basket away" is exactly the
     * kind of thing an audit trail exists to answer, and the row is the only
     * record that this basket ever existed.
     */
    public function release(HeldSale $hold): void
    {
        $hold->forceFill(['released_at' => now()])->save();

        AuditLogger::log(
            'held.released',
            HeldSale::class,
            $hold->id,
            null,
            ['label' => $hold->label],
            'Held sale discarded: '.$hold->label,
            $hold->branch_id
        );
    }

    /* ------------------------------------------------------------------ */

    /** @return array<int, Product> keyed by id, with tax and unit loaded */
    protected function resolveProducts(array $lines): array
    {
        $ids = array_values(array_unique(array_map(
            fn ($line) => (int) ($line['product_id'] ?? 0),
            $lines
        )));

        $ids = array_values(array_filter($ids));

        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->with(['taxRate', 'unit'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Display-only total for the hold list.
     *
     * Deliberately NOT the payable total: this is what the basket looked like
     * when it was parked, and the server has not been asked to tax it. The real
     * figure is produced by SaleService when the cart is actually charged.
     */
    protected function previewTotal(array $lines, $discount, array $products): string
    {
        $gross = '0';

        foreach ($lines as $line) {
            $product = $products[$line['product_id']] ?? null;
            $unit = $product ? $product->selling_price : ($line['unit_price'] ?? '0');

            $lineNet = Money::mul(
                Money::round($unit, 2),
                Money::round($line['quantity'] ?? '1', 4),
                2
            );

            $gross = Money::add($gross, Money::sub($lineNet, Money::round($line['discount_amount'] ?? '0', 2)), 2);
        }

        return Money::sub($gross, Money::round($discount, 2), 2);
    }

    /**
     * Stock available per product at a warehouse, keyed by product id.
     *
     * One query for the whole cart rather than one per line: a resumed basket
     * can easily hold twenty lines, and resuming must not fire twenty queries
     * just to tell the cashier what is on the shelf.
     *
     * @return array<int, string>
     */
    protected function availability(?int $warehouseId, array $productIds): array
    {
        if ($warehouseId === null || $productIds === []) {
            return [];
        }

        return Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->whereNull('product_variant_id')
            ->get()
            ->mapWithKeys(fn (Stock $stock) => [
                $stock->product_id => Money::sub(
                    Money::round($stock->quantity, 4),
                    Money::round($stock->reserved_quantity ?? '0', 4),
                    4
                ),
            ])
            ->all();
    }
}