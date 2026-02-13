<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionTier extends Model
{
    /** @use HasFactory<\Database\Factories\SubscriptionTierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'stripe_price_id_monthly',
        'stripe_price_id_yearly',
        'features',
        'permissions',
        'data_permissions',
        'predictions_limit',
        'team_metrics_limit',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'features' => 'array',
            'permissions' => 'array',
            'data_permissions' => 'array',
            'predictions_limit' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function getTeamMetricsLimit(): ?int
    {
        return $this->team_metrics_limit;
    }

    public function hasDataPermission(string $field): bool
    {
        return in_array($field, $this->data_permissions ?? []);
    }

    public function getPredictionsLimit(): ?int
    {
        return $this->features['predictions_per_day'] ?? null;
    }

    public function hasUnlimitedPredictions(): bool
    {
        return ($this->features['predictions_per_day'] ?? null) === null;
    }
}
