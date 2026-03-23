<?php

namespace App\Http\Resources\Admin;

use App\Models\Healthcheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Healthcheck */
class HealthcheckSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sport' => $this->sport,
            'check_type' => $this->check_type,
            'status' => $this->status,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'checked_at' => $this->checked_at?->toDateTimeString(),
        ];
    }
}
