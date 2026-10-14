<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parked sale.
 *
 * The cart is stored verbatim in `payload` and is never trusted on resume: the
 * POS re-prices every line from the live product record, so a hold parked
 * before a price change cannot be completed at yesterday's price.
 *
 * Deliberately NOT soft-deleting. `resumed_at` and `released_at` already say
 * exactly where a hold ended up, and a third "is it gone?" flag can only ever
 * disagree with one of them — a row soft-deleted but never released reads as
 * live to the release query and gone to the resume query. "The cashier threw
 * this basket away" is recorded by `released_at` and the audit log, which is
 * the whole point of keeping the row.
 */
class HeldSale extends Model
{
    use HasFactory;

    protected $table = 'held_sales';

    protected $fillable = [
        'reference',
        'branch_id',
        'warehouse_id',
        'cash_register_session_id',
        'customer_id',
        'user_id',
        'label',
        'payload',
        'line_count',
        'cached_total',
        'parked_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'line_count' => 'integer',
        'cached_total' => 'decimal:4',
        'parked_at' => 'datetime',
        'resumed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    /**
     * Holds are addressed by their public reference, never by row id.
     *
     * `reference` is what the till shows and what a cashier reads over a
     * counter, and an auto-increment id is both guessable and meaningless to
     * them. Binding on it also means the resume URL does not leak that parking
     * three baskets produced id 11.
     */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    /** Holds still waiting to be picked up, newest first. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('resumed_at')->whereNull('released_at');
    }

    public function isLive(): bool
    {
        return $this->resumed_at === null && $this->released_at === null;
    }
}