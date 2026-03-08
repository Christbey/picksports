<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tier = $this->subscriptionTier();
        $isSubscribed = $this->subscribed() || $this->hasFoundingAccess();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_admin' => (bool) $this->is_admin,
            'is_subscribed' => $isSubscribed,
            'is_founding_user' => $this->hasFoundingAccess(),
            'tier' => [
                'slug' => $tier?->slug,
                'name' => $tier?->name,
            ],
            'roles' => $this->getRoleNames()->values()->all(),
            'permissions' => $this->getAllPermissions()
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
