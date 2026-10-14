<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'company', 'phone', 'email', 'address', 'city',
        'tax_number', 'opening_balance', 'notes', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function outstandingPayable(): string
    {
        return (string) $this->purchases()
            ->whereIn('status', ['received', 'partial'])
            ->sum('due_total');
    }
}
