<?php

namespace App\Models;

use App\Services\Auth\Native\NativeCredentialPolicy;
use Database\Factories\DeviceSessionRefreshTokenFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'native_policy_version',
        'expires_epoch',
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
            'expires_epoch' => 'integer',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected function expiresAt(): Attribute
    {
        return Attribute::get(function ($value) {
            $version = $this->getAttribute('native_policy_version');
            $epoch = $this->attributes['expires_epoch'] ?? null;
            if ($version !== null || $epoch !== null) {
                return NativeCredentialPolicy::instant($version, $epoch, $value);
            }

            return $value === null ? null : $this->asDateTime($value)->toImmutable();
        });
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
