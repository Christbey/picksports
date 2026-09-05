<?php

namespace App\Models;

use Database\Factories\DeviceSessionFactory;
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
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
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
