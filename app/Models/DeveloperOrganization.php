<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperOrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperOrganization extends Model
{
    /** @use HasFactory<DeveloperOrganizationFactory> */
    use HasFactory, HasPublicUlid;

    protected $fillable = ['public_id', 'name', 'slug', 'status', 'created_by_user_id'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(DeveloperOrganizationMembership::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeveloperApiCredential::class);
    }

    public function entitlementPolicies(): HasMany
    {
        return $this->hasMany(DeveloperEntitlementPolicy::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(DeveloperApiUsageRecord::class);
    }

    public function meterBatchItems(): HasMany
    {
        return $this->hasMany(DeveloperMeterBatchItem::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(DeveloperWebhookEndpoint::class);
    }

    public function webhookOutboxEvents(): HasMany
    {
        return $this->hasMany(DeveloperWebhookOutboxEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    protected static function newFactory(): DeveloperOrganizationFactory
    {
        return DeveloperOrganizationFactory::new();
    }
}
