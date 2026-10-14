<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Central authority for all stock movements.
 * Every change updates the stock balance AND writes an auditable movement
 * record within the same database transaction.
 */
class InventoryService
{
    /**
     * Increase stock (purchases, returns, opening, transfer in).
     */
    public function increase(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        string $quantity,
        string $unitCost,
        string $type,
        $reference = null,
        ?int $userId = null,
        ?string $memo = null
    ): StockMovement {
        return $this->apply($warehouseId, $productId, $variantId, $quantity, $unitCost, $type, $reference, $userId, $memo, true);
    }

    /**
     * Decrease stock (sales, transfers out, damage).
     */
    public function decrease(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        string $quantity,
        string $unitCost,
        string $type,
        $reference = null,
        ?int $userId = null,
        ?string $memo = null
    ): StockMovement {
        return $this->apply($warehouseId, $productId, $variantId, $quantity, $unitCost, $type, $reference, $userId, $memo, false);
    }

    protected function apply(
        int $warehouseId,
        int $productId,
        ?int $variantId,
        string $quantity,
        string $unitCost,
        string $type,
        $reference,
        ?int $userId,
        ?string $memo,
        bool $isIncrease
    ): StockMovement {
        $quantity = $this->abs($quantity);
        $signedQty = $isIncrease ? $quantity : Money::sub('0', $quantity, 4);

        // Lock the stock row for this warehouse/product/variant.
        $stock = Stock::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $stock = Stock::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity' => '0',
                'reserved_quantity' => '0',
                'avg_cost' => '0',
            ]);
        }

        $newQuantity = Money::add((string) $stock->quantity, $signedQty, 4);

        // Prevent negative stock when the product disallows it.
        if (Money::isNegative($newQuantity)) {
            $product = Product::find($productId);
            if (! $product || ! $product->allow_negative_stock) {
                throw new InsufficientStockException(
                    __('Insufficient stock for :product. Available: :available, requested: :requested.', [
                        'product' => $product?->name ?? ('product #' . $productId),
                        'available' => (string) $stock->quantity,
                        'requested' => $quantity,
                    ]),
                    $warehouseId,
                    $productId,
                    $quantity,
                    (string) $stock->quantity,
                );
            }
        }

        // Weighted average cost recalculation on increase.
        $newAvgCost = (string) $stock->avg_cost;
        if ($isIncrease && ! Money::isZero($quantity)) {
            $existingValue = Money::mul((string) $stock->quantity, (string) $stock->avg_cost, 4);
            $addedValue = Money::mul($quantity, $unitCost, 4);
            $newAvgCost = Money::div(Money::add($existingValue, $addedValue, 4), $newQuantity, 4);
        }

        $stock->update([
            'quantity' => $newQuantity,
            'avg_cost' => $newAvgCost,
        ]);

        $movement = StockMovement::create([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => $signedQty,
            'balance_after' => $newQuantity,
            'unit_cost' => $unitCost,
            'type' => $type,
            'reference_type' => $reference ? get_class($reference) : null,
            'reference_id' => $reference ? $reference->getKey() : null,
            'memo' => $memo,
            'user_id' => $userId,
            'moved_at' => now(),
        ]);

        // Routine movements are already covered by the document that caused them
        // (the sale, the purchase, the return). A manual correction is not, so
        // it gets its own audit entry — that is the one a bookkeeper needs to
        // see later.
        if (in_array($type, self::AUDITED_TYPES, true)) {
            AuditLogger::log(
                'stock.' . $type,
                StockMovement::class,
                $movement->id,
                null,
                [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => $signedQty,
                    'balance_after' => $newQuantity,
                    'unit_cost' => $unitCost,
                    'memo' => $memo,
                ],
                __('Stock :type of :qty for product # :product', [
                    'type' => $type,
                    'qty' => $quantity,
                    'product' => $productId,
                ]),
                $this->branchForWarehouse($warehouseId)
            );
        }

        return $movement;
    }

    /**
     * Movement types that get a dedicated audit entry of their own.
     */
    protected const AUDITED_TYPES = ['adjustment', 'damage', 'transfer_in', 'transfer_out', 'opening'];

    /**
     * Resolve a warehouse's branch so audit entries carry the right context.
     */
    protected function branchForWarehouse(int $warehouseId): ?int
    {
        static $cache = [];

        if (! array_key_exists($warehouseId, $cache)) {
            $cache[$warehouseId] = Warehouse::where('id', $warehouseId)->value('branch_id');
        }

        return $cache[$warehouseId];
    }

    /**
     * Current available quantity for a product at a warehouse.
     */
    public function available(int $warehouseId, int $productId, ?int $variantId = null): string
    {
        $stock = Stock::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if (! $stock) {
            return '0';
        }

        return Money::sub((string) $stock->quantity, (string) $stock->reserved_quantity, 4);
    }

    protected function abs(string $value): string
    {
        return ltrim($value, '-') === $value && Money::isNegative($value) ? substr($value, 1) : $value;
    }
}
