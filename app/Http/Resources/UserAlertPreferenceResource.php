<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserAlertPreferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enabled' => $this->enabled,
            'sports' => $this->sports,
            'notification_types' => $this->notification_types,
            'minimum_edge' => $this->minimum_edge,
            'time_window_start' => $this->time_window_start?->format('H:i'),
            'time_window_end' => $this->time_window_end?->format('H:i'),
            'digest_mode' => $this->digest_mode,
            'digest_time' => $this->digest_time?->format('H:i'),
            'phone_number' => $this->phone_number,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
