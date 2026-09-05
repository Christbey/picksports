<?php

namespace App\Models;

use Database\Factories\DeviceSessionRefreshTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSessionRefreshToken extends Model
{
    /** @use HasFactory<DeviceSessionRefreshTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'device_session_id',
        'token_hash',
        'replaced_by_token_id',
        'expires_at',
        'used_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(DeviceSession::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_token_id');
    }
}
