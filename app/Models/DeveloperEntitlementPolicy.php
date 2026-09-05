<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperEntitlementPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperEntitlementPolicy extends Model
{
    /** @use HasFactory<DeveloperEntitlementPolicyFactory> */
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'developer_organization_id',
        'developer_product_id',
        'status',
        'scopes',
        'limits',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'limits' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(DeveloperOrganization::class, 'developer_organization_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(DeveloperProduct::class, 'developer_product_id');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(DeveloperApiUsageRecord::class);
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function allowsScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    protected static function newFactory(): DeveloperEntitlementPolicyFactory
    {
        return DeveloperEntitlementPolicyFactory::new();
    }
}
