<?php

namespace App\Http\Resources\Settings;

use App\Models\OddsApiTeamMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OddsApiTeamMapping */
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
            'external_team_name' => $this->odds_api_team_name,
            'external_team_id' => $this->odds_api_team_id,
            'sport' => $this->sport,
        ];
    }
}
