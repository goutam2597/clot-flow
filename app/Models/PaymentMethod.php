<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'type', 'requires_reference', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'requires_reference' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }
}
