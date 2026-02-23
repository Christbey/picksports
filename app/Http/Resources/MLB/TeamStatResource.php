<?php

namespace App\Http\Resources\MLB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamStatResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'game_id' => $this->game_id,
            'team_type' => $this->team_type,
            'runs' => $this->runs,
            'hits' => $this->hits,
            'errors' => $this->errors,
            'at_bats' => $this->at_bats,
            'doubles' => $this->doubles,
            'triples' => $this->triples,
            'home_runs' => $this->home_runs,
            'rbis' => $this->rbis,
            'walks' => $this->walks,
            'strikeouts' => $this->strikeouts,
            'stolen_bases' => $this->stolen_bases,
            'left_on_base' => $this->left_on_base,
            'batting_average' => $this->batting_average,
            'pitchers_used' => $this->pitchers_used,
            'innings_pitched' => $this->innings_pitched,
            'hits_allowed' => $this->hits_allowed,
            'runs_allowed' => $this->runs_allowed,
            'earned_runs' => $this->earned_runs,
            'walks_allowed' => $this->walks_allowed,
            'strikeouts_pitched' => $this->strikeouts_pitched,
            'home_runs_allowed' => $this->home_runs_allowed,
            'total_pitches' => $this->total_pitches,
            'era' => $this->era,
            'putouts' => $this->putouts,
            'assists' => $this->assists,
            'double_plays' => $this->double_plays,
            'passed_balls' => $this->passed_balls,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'team' => TeamResource::make($this->whenLoaded('team')),
            'game' => GameResource::make($this->whenLoaded('game')),
        ];
    }
}
