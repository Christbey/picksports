<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    use MassPrunable;

    protected $fillable = [
        'principal_type',
        'principal_id',
        'route_scope',
        'key_hash',
        'scope_hash',
        'request_fingerprint',
        'response_status',
        'response_headers',
        'response_body',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_headers' => 'array',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
