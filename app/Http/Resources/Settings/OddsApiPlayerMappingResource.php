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
            'espn_player_id' => $this->espn_player_id,
            'odds_api_player_name' => $this->odds_api_player_name,
            'suggested_espn_player_name' => $this->suggested_espn_player_name,
            'suggested_player_id' => $this->suggested_player_id,
            'suggested_match_quality_score' => $this->suggested_match_quality_score,
            'sport' => $this->sport,
        ];
    }
}
