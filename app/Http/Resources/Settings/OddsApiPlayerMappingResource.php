<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OddsApiPlayerMapping */
class OddsApiPlayerMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'espn_player_name' => $this->espn_player_name,
            'odds_api_player_name' => $this->odds_api_player_name,
            'sport' => $this->sport,
        ];
    }
}
