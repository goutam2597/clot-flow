<?php

namespace App\Models;

use App\Models\Concerns\ScopedToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes, ScopedToBranch;

    protected $fillable = [
        'branch_id', 'code', 'name', 'phone', 'email', 'address', 'city',
        'tax_number', 'opening_balance', 'credit_limit', 'type', 'notes', 'is_active',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Outstanding balance derived from sale due amounts.
     */
    public function outstandingBalance(): string
    {
        return (string) $this->sales()
            ->whereIn('status', ['completed'])
            ->sum('due_total');
    }
}
