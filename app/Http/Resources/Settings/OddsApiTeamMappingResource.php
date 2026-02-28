<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OddsApiTeamMapping */
class OddsApiTeamMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'espn_team_name' => $this->espn_team_name,
            'odds_api_team_name' => $this->odds_api_team_name,
            'sport' => $this->sport,
        ];
    }
}
