<?php

namespace App\Http\Resources\Settings;

use App\Models\CfbdTeamMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CfbdTeamMapping */
class CfbdTeamMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'espn_team_name' => $this->espn_team_name,
            'external_team_name' => $this->cfbd_team_name,
            'external_team_abbreviation' => $this->cfbd_abbreviation,
            'external_team_id' => $this->cfbd_team_id,
            'suggested_espn_team_name' => $this->suggested_espn_team_name,
            'suggested_match_quality_score' => $this->suggested_match_quality_score,
            'sport' => $this->sport,
            'conference' => $this->conference,
            'division' => $this->division,
            'alternate_names' => $this->alternate_names ?? [],
        ];
    }
}
