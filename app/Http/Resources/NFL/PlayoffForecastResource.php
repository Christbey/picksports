<?php

namespace App\Http\Resources\NFL;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayoffForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'team_id' => (int) data_get($this->resource, 'team_id'),
            'team_name' => (string) data_get($this->resource, 'team_name'),
            'conference' => data_get($this->resource, 'conference'),
            'division' => data_get($this->resource, 'division'),
            'projected_wins' => (float) data_get($this->resource, 'projected_wins', 0.0),
            'projected_seed' => data_get($this->resource, 'projected_seed') !== null
                ? (float) data_get($this->resource, 'projected_seed')
                : null,
            'division_winner_probability' => (float) data_get($this->resource, 'division_winner_probability', 0.0),
            'make_playoffs_probability' => (float) data_get($this->resource, 'make_playoffs_probability', 0.0),
            'conference_champion_probability' => (float) data_get($this->resource, 'conference_champion_probability', 0.0),
            'super_bowl_champion_probability' => (float) data_get($this->resource, 'super_bowl_champion_probability', 0.0),
            'market_odds' => data_get($this->resource, 'market_odds'),
            'market_edge' => data_get($this->resource, 'market_edge'),
        ];
    }
}
