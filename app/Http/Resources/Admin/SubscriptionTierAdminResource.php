<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SubscriptionTier */
class SubscriptionTierAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price_monthly' => $this->price_monthly,
            'price_yearly' => $this->price_yearly,
            'stripe_price_id_monthly' => $this->stripe_price_id_monthly,
            'stripe_price_id_yearly' => $this->stripe_price_id_yearly,
            'features' => $this->features,
            'permissions' => $this->permissions,
            'data_permissions' => $this->data_permissions,
            'predictions_limit' => $this->predictions_limit,
            'team_metrics_limit' => $this->team_metrics_limit,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
