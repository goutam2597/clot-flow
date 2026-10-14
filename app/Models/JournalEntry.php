<?php

namespace App\Models;

use App\Models\Concerns\ScopedToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    use HasFactory, ScopedToBranch;

    protected $fillable = [
        'entry_number', 'entry_date', 'reference_type', 'reference_id',
        'description', 'branch_id', 'user_id', 'status', 'reversed_by_entry_id', 'idempotency_key',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by_entry_id');
    }

    /**
     * Total debits. Uses bcmath, not PHP's float addition, so a long entry
     * cannot drift by a cent.
     */
    public function getTotalDebitAttribute(): string
    {
        return \App\Support\Money::sum(
            $this->lines->map(fn ($line) => (string) $line->debit)->all(),
            2
        );
    }

    public function getTotalCreditAttribute(): string
    {
        return \App\Support\Money::sum(
            $this->lines->map(fn ($line) => (string) $line->credit)->all(),
            2
        );
    }

    public function isBalanced(): bool
    {
        return bccomp($this->total_debit, $this->total_credit, 2) === 0;
    }
}
