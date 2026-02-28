<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class AdminUserSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->subscriptions->first();
        $tier = $this->subscriptionTier();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tier' => $tier?->name ?? 'Unknown',
            'subscription' => $subscription ? [
                'stripe_id' => $subscription->stripe_id,
                'stripe_status' => $subscription->stripe_status,
                'stripe_price' => $subscription->stripe_price,
                'trial_ends_at' => $subscription->trial_ends_at?->toDateTimeString(),
                'ends_at' => $subscription->ends_at?->toDateTimeString(),
                'created_at' => $subscription->created_at?->toDateTimeString(),
            ] : null,
        ];
    }
}
