<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperApiUsageRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DeveloperApiUsageRecord extends Model
{
    /** @use HasFactory<DeveloperApiUsageRecordFactory> */
    use HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'developer_organization_id',
        'developer_api_credential_id',
        'developer_product_id',
        'developer_entitlement_policy_id',
        'request_id',
        'operation',
        'scope',
        'units',
        'status_code',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'status_code' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Developer API usage records are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Developer API usage records are immutable.'));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(DeveloperOrganization::class, 'developer_organization_id');
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(DeveloperApiCredential::class, 'developer_api_credential_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(DeveloperProduct::class, 'developer_product_id');
    }

    public function entitlementPolicy(): BelongsTo
    {
        return $this->belongsTo(DeveloperEntitlementPolicy::class, 'developer_entitlement_policy_id');
    }

    protected static function newFactory(): DeveloperApiUsageRecordFactory
    {
        return DeveloperApiUsageRecordFactory::new();
    }
}
