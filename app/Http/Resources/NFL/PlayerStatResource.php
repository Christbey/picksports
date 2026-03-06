<?php

namespace App\Http\Resources\NFL;

use App\Services\PlayerStats\NflPlayerEpaCalculator;
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
        $epaCalculator = app(NflPlayerEpaCalculator::class);

        $estimatedEpa = $epaCalculator->estimateFromBoxScore([
            'passing_yards' => (float) ($this->passing_yards ?? 0),
            'passing_touchdowns' => (float) ($this->passing_touchdowns ?? 0),
            'interceptions_thrown' => (float) ($this->interceptions_thrown ?? 0),
            'sacks_taken' => (float) ($this->sacks_taken ?? 0),
            'sack_yards_lost' => (float) ($this->sack_yards_lost ?? 0),
            'passing_two_point_conversions' => (float) ($this->passing_two_point_conversions ?? 0),
            'rushing_yards' => (float) ($this->rushing_yards ?? 0),
            'rushing_touchdowns' => (float) ($this->rushing_touchdowns ?? 0),
            'rushing_attempts' => (float) ($this->rushing_attempts ?? 0),
            'rushing_two_point_conversions' => (float) ($this->rushing_two_point_conversions ?? 0),
            'receptions' => (float) ($this->receptions ?? 0),
            'receiving_yards' => (float) ($this->receiving_yards ?? 0),
            'receiving_touchdowns' => (float) ($this->receiving_touchdowns ?? 0),
            'receiving_targets' => (float) ($this->receiving_targets ?? 0),
            'receiving_two_point_conversions' => (float) ($this->receiving_two_point_conversions ?? 0),
            'kickoff_return_yards' => (float) ($this->kickoff_return_yards ?? 0),
            'kickoff_return_touchdowns' => (float) ($this->kickoff_return_touchdowns ?? 0),
            'punt_return_yards' => (float) ($this->punt_return_yards ?? 0),
            'punt_return_touchdowns' => (float) ($this->punt_return_touchdowns ?? 0),
            'tackles_total' => (float) ($this->tackles_total ?? 0),
            'sacks' => (float) ($this->sacks ?? 0),
            'interceptions' => (float) ($this->interceptions ?? 0),
            'passes_defended' => (float) ($this->passes_defended ?? 0),
            'fumbles_recovered' => (float) ($this->fumbles_recovered ?? 0),
            'field_goals_made' => (float) ($this->field_goals_made ?? 0),
            'field_goals_attempted' => (float) ($this->field_goals_attempted ?? 0),
            'extra_points_made' => (float) ($this->extra_points_made ?? 0),
            'extra_points_attempted' => (float) ($this->extra_points_attempted ?? 0),
        ]);
        $opportunities = (float) ($this->passing_attempts ?? 0)
            + (float) ($this->rushing_attempts ?? 0)
            + (float) ($this->receiving_targets ?? 0)
            + (float) ($this->kickoff_returns ?? 0)
            + (float) ($this->punt_returns ?? 0)
            + (float) ($this->field_goals_attempted ?? 0)
            + (float) ($this->extra_points_attempted ?? 0)
            + (float) ($this->tackles_total ?? 0)
            + (float) ($this->passes_defended ?? 0)
            + (float) ($this->interceptions ?? 0)
            + (float) ($this->fumbles_recovered ?? 0);
        $estimatedEpaPerOpportunity = $epaCalculator->estimatePerOpportunity($estimatedEpa, $opportunities);

        return [
            'id' => $this->id,
            'player_id' => $this->player_id,
            'game_id' => $this->game_id,
            'team_id' => $this->team_id,
            'passing_completions' => $this->passing_completions,
            'passing_attempts' => $this->passing_attempts,
            'passing_yards' => $this->passing_yards,
            'passing_touchdowns' => $this->passing_touchdowns,
            'interceptions_thrown' => $this->interceptions_thrown,
            'passing_long' => $this->passing_long,
            'sack_yards_lost' => $this->sack_yards_lost,
            'passing_two_point_conversions' => $this->passing_two_point_conversions,
            'rushing_attempts' => $this->rushing_attempts,
            'rushing_yards' => $this->rushing_yards,
            'rushing_touchdowns' => $this->rushing_touchdowns,
            'rushing_long' => $this->rushing_long,
            'rushing_two_point_conversions' => $this->rushing_two_point_conversions,
            'receptions' => $this->receptions,
            'receiving_yards' => $this->receiving_yards,
            'receiving_touchdowns' => $this->receiving_touchdowns,
            'receiving_targets' => $this->receiving_targets,
            'receiving_long' => $this->receiving_long,
            'receiving_two_point_conversions' => $this->receiving_two_point_conversions,
            'kickoff_returns' => $this->kickoff_returns,
            'kickoff_return_yards' => $this->kickoff_return_yards,
            'kickoff_return_touchdowns' => $this->kickoff_return_touchdowns,
            'kickoff_return_long' => $this->kickoff_return_long,
            'kickoff_return_fair_catches' => $this->kickoff_return_fair_catches,
            'punt_returns' => $this->punt_returns,
            'punt_return_yards' => $this->punt_return_yards,
            'punt_return_touchdowns' => $this->punt_return_touchdowns,
            'punt_return_long' => $this->punt_return_long,
            'punt_return_fair_catches' => $this->punt_return_fair_catches,
            'tackles_total' => $this->tackles_total,
            'sacks' => $this->sacks,
            'interceptions' => $this->interceptions,
            'passes_defended' => $this->passes_defended,
            'fumbles_recovered' => $this->fumbles_recovered,
            'field_goals_made' => $this->field_goals_made,
            'field_goals_attempted' => $this->field_goals_attempted,
            'extra_points_made' => $this->extra_points_made,
            'extra_points_attempted' => $this->extra_points_attempted,
            'estimated_epa' => $estimatedEpa,
            'estimated_epa_per_opportunity' => $estimatedEpaPerOpportunity,
            'total_yards' => (int) (($this->passing_yards ?? 0) + ($this->rushing_yards ?? 0) + ($this->receiving_yards ?? 0)),
            'total_touchdowns' => (int) (($this->passing_touchdowns ?? 0) + ($this->rushing_touchdowns ?? 0) + ($this->receiving_touchdowns ?? 0)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'player' => PlayerResource::make($this->whenLoaded('player')),
            'game' => GameResource::make($this->whenLoaded('game')),
            'team' => TeamResource::make($this->whenLoaded('team')),
        ];
    }
}
