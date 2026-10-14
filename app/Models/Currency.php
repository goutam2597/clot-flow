<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'symbol', 'decimal_places', 'symbol_position',
        'thousand_separator', 'decimal_separator', 'exchange_rate', 'is_active', 'is_default',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'exchange_rate' => 'decimal:6',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public static function default(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::where('is_active', true)->orderBy('id')->first();
    }
}
