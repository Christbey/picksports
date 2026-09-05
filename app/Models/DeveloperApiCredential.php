<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperApiCredentialFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperApiCredential extends Model implements AuthenticatableContract
{
    /** @use HasFactory<DeveloperApiCredentialFactory> */
    use Authenticatable, HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'developer_organization_id',
        'created_by_user_id',
        'name',
        'prefix',
        'secret_hash',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(DeveloperOrganization::class, 'developer_organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(DeveloperApiUsageRecord::class);
    }

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->organization?->isActive() === true;
    }

    protected static function newFactory(): DeveloperApiCredentialFactory
    {
        return DeveloperApiCredentialFactory::new();
    }
}
