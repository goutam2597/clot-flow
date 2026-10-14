<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id', 'product_id', 'product_variant_id', 'tax_rate_id',
        'quantity', 'received_quantity', 'unit_cost', 'discount_amount',
        'order_discount_share', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'received_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'order_discount_share' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    /**
     * Total discount on this line: its own discount plus its share of the
     * document-level discount.
     */
    public function getTotalDiscountAttribute(): string
    {
        return \App\Support\Money::add(
            (string) $this->discount_amount,
            (string) $this->order_discount_share,
            2
        );
    }

    /**
     * How much of this line is still outstanding.
     */
    public function getPendingQuantityAttribute(): string
    {
        return \App\Support\Money::sub(
            (string) $this->quantity,
            (string) $this->received_quantity,
            4
        );
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
