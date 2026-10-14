<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a stock movement would take a balance below zero and the product
 * does not allow negative stock.
 *
 * Offline synchronization treats this specially: a terminal that sold stock
 * which has since been sold elsewhere must not overwrite the authoritative
 * balance, so the queued sale is parked for a human instead of being forced
 * through.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $warehouseId = 0,
        public readonly int $productId = 0,
        public readonly string $requested = '0',
        public readonly string $available = '0',
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function context(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'product_id' => $this->productId,
            'requested' => $this->requested,
            'available' => $this->available,
        ];
    }
}
