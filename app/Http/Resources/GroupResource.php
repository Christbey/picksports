<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'type' => $this->type,
            'sport' => $this->sport,
            'season' => $this->season,
            'owner_id' => $this->owner_id,
            'settings' => $this->settings ?? [],
        ];
    }
}
