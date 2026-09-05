<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperProduct extends Model
{
    /** @use HasFactory<DeveloperProductFactory> */
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'code',
        'name',
        'description',
        'is_active',
        'default_scopes',
        'default_limits',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_scopes' => 'array',
            'default_limits' => 'array',
        ];
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

    protected static function newFactory(): DeveloperProductFactory
    {
        return DeveloperProductFactory::new();
    }
}
