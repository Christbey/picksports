<?php

namespace App\Models;

use Database\Factories\DevicePushRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePushRegistration extends Model
{
    /** @use HasFactory<DevicePushRegistrationFactory> */
    use HasFactory;

    protected $fillable = [
        'device_session_id',
        'provider',
        'token_hash',
        'device_token',
        'environment',
        'last_registered_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
        'device_token',
    ];

    protected function casts(): array
    {
        return [
            'device_token' => 'encrypted',
            'last_registered_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(DeviceSession::class);
    }
}
