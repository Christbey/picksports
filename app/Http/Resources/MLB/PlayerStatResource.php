<?php

namespace App\Http\Resources\MLB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerStatResource extends JsonResource
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
            'player_id' => $this->player_id,
            'game_id' => $this->game_id,
            'team_id' => $this->team_id,
            'stat_type' => $this->stat_type,
            'at_bats' => $this->at_bats,
            'runs' => $this->runs,
            'hits' => $this->hits,
            'doubles' => $this->doubles,
            'triples' => $this->triples,
            'home_runs' => $this->home_runs,
            'rbis' => $this->rbis,
            'walks' => $this->walks,
            'strikeouts' => $this->strikeouts,
            'stolen_bases' => $this->stolen_bases,
            'caught_stealing' => $this->caught_stealing,
            'batting_average' => $this->batting_average,
            'on_base_percentage' => $this->on_base_percentage,
            'slugging_percentage' => $this->slugging_percentage,
            'innings_pitched' => $this->innings_pitched,
            'hits_allowed' => $this->hits_allowed,
            'runs_allowed' => $this->runs_allowed,
            'earned_runs' => $this->earned_runs,
            'walks_allowed' => $this->walks_allowed,
            'strikeouts_pitched' => $this->strikeouts_pitched,
            'home_runs_allowed' => $this->home_runs_allowed,
            'era' => $this->era,
            'pitches_thrown' => $this->pitches_thrown,
            'pitch_count' => $this->pitch_count,
            'putouts' => $this->putouts,
            'assists' => $this->assists,
            'errors' => $this->errors,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'player' => PlayerResource::make($this->whenLoaded('player')),
            'game' => GameResource::make($this->whenLoaded('game')),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
