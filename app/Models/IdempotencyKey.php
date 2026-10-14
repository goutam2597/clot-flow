<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'key', 'endpoint', 'resource_type', 'resource_id', 'status', 'response_payload', 'user_id',
    ];

    protected $casts = [
        'response_payload' => 'array',
    ];
}
