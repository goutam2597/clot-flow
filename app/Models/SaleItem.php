<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'product_id', 'product_variant_id', 'tax_rate_id',
        'product_name', 'product_sku', 'quantity', 'unit_price', 'unit_cost',
        'discount_amount', 'order_discount_share', 'tax_amount', 'tax_rate', 'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'order_discount_share' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:2',
    ];

    /**
     * Total discount applied to this line: its own discount plus its share of
     * any document-level discount.
     */
    public function getTotalDiscountAttribute(): string
    {
        return \App\Support\Money::add(
            (string) $this->discount_amount,
            (string) $this->order_discount_share,
            2
        );
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
