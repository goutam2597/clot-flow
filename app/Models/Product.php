<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id', 'brand_id', 'unit_id', 'tax_rate_id', 'name', 'sku',
        'barcode', 'type', 'description', 'image_path', 'cost_price',
        'selling_price', 'track_stock', 'allow_negative_stock', 'reorder_level', 'status',
    ];

    protected $casts = [
        'cost_price' => 'decimal:4',
        'selling_price' => 'decimal:4',
        'track_stock' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'reorder_level' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Every movement recorded for this product, across all warehouses.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * A ready-to-render image URL.
     *
     * `image_path` holds either a local upload under storage/ or an absolute
     * URL. Both are legitimate: a shop uploads its own photographs, while an
     * imported catalogue points at a supplier's or a CDN's copy. Conflating the
     * two — always prefixing `storage/`, say — turns every remote image into a
     * broken `<img src="storage/https://…">`, which renders as a silent blank
     * tile in the POS grid rather than as an error anyone notices.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        return preg_match('#^https?://#i', $this->image_path)
            ? $this->image_path
            : asset('storage/' . ltrim($this->image_path, '/'));
    }
}
