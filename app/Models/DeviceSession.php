<?php

namespace App\Models;

use App\Services\Auth\Native\NativeCredentialPolicy;
use Database\Factories\DeviceSessionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceSession extends Model
{
    /** @use HasFactory<DeviceSessionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'public_id',
        'user_id',
        'access_token_id',
        'token_family_id',
        'device_name',
        'platform',
        'device_identifier_hash',
        'abilities',
        'access_token_expires_at',
        'native_policy_version',
        'access_expires_epoch',
        'last_used_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = [
        'device_identifier_hash',
    ];

    public function uniqueIds(): array
    {
        return ['public_id', 'token_family_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'access_token_expires_at' => 'immutable_datetime',
            'access_expires_epoch' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected function accessTokenExpiresAt(): Attribute
    {
        return Attribute::get(function ($value) {
            $version = $this->getAttribute('native_policy_version');
            $epoch = $this->attributes['access_expires_epoch'] ?? null;
            if ($version !== null || $epoch !== null) {
                return NativeCredentialPolicy::instant($version, $epoch, $value);
            }

            return $value === null ? null : $this->asDateTime($value)->toImmutable();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'access_token_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(DeviceSessionRefreshToken::class);
    }

    public function pushRegistrations(): HasMany
    {
        return $this->hasMany(DevicePushRegistration::class);
    }
}
