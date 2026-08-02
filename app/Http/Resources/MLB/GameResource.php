<?php

namespace App\Http\Resources\MLB;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
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
            'espn_id' => $this->espn_event_id,
            'espn_event_id' => $this->espn_event_id,
            'espn_uid' => $this->espn_uid,
            'season' => $this->season,
            'week' => $this->week,
            'season_type' => $this->season_type,
            'game_date' => $this->game_date?->toDateString(),
            'game_time' => $this->game_time,
            'venue' => $this->venue_name,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'home_team_id' => $this->home_team_id,
            'away_team_id' => $this->away_team_id,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'home_linescores' => $this->home_linescores,
            'away_linescores' => $this->away_linescores,
            'status' => $this->status,
            'inning' => $this->inning,
            'inning_half' => $this->inning_half,
            'balls' => $this->balls,
            'strikes' => $this->strikes,
            'outs' => $this->outs,
            'probable_home_pitcher_espn_id' => $this->probable_home_pitcher_espn_id,
            'probable_away_pitcher_espn_id' => $this->probable_away_pitcher_espn_id,
            'actual_home_pitcher_espn_id' => $this->actual_home_pitcher_espn_id,
            'actual_away_pitcher_espn_id' => $this->actual_away_pitcher_espn_id,
            'projected_home_pitcher_espn_id' => $this->projected_home_pitcher_espn_id,
            'projected_away_pitcher_espn_id' => $this->projected_away_pitcher_espn_id,
            'home_starting_pitcher_source' => $this->resource->startingPitcherSource('home'),
            'away_starting_pitcher_source' => $this->resource->startingPitcherSource('away'),
            'home_starting_pitcher_confidence' => $this->resource->startingPitcherConfidence('home'),
            'away_starting_pitcher_confidence' => $this->resource->startingPitcherConfidence('away'),
            'pitcher_projection_metadata' => $this->pitcher_projection_metadata,
            'pitcher_projection_generated_at' => $this->pitcher_projection_generated_at?->toIso8601String(),
            'starting_pitcher_confirmation_metadata' => $this->starting_pitcher_confirmation_metadata,
            'starting_pitchers_confirmed_at' => $this->starting_pitchers_confirmed_at?->toIso8601String(),
            'venue_name' => $this->venue_name,
            'venue_city' => $this->venue_city,
            'venue_state' => $this->venue_state,
            'broadcast_networks' => $this->broadcast_networks,
            'matchup_context' => $this->resource->getAttribute('matchup_context'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'home_team' => TeamResource::make($this->whenLoaded('homeTeam')),
            'away_team' => TeamResource::make($this->whenLoaded('awayTeam')),
            'home_starting_pitcher' => PlayerResource::make(
                filled($this->actual_home_pitcher_espn_id)
                    ? $this->whenLoaded('actualHomePitcher')
                    : (filled($this->probable_home_pitcher_espn_id)
                    ? $this->whenLoaded('probableHomePitcher')
                    : $this->whenLoaded('projectedHomePitcher'))
            ),
            'away_starting_pitcher' => PlayerResource::make(
                filled($this->actual_away_pitcher_espn_id)
                    ? $this->whenLoaded('actualAwayPitcher')
                    : (filled($this->probable_away_pitcher_espn_id)
                    ? $this->whenLoaded('probableAwayPitcher')
                    : $this->whenLoaded('projectedAwayPitcher'))
            ),
            'home_starting_pitcher_forecast' => $this->startingPitcherForecastPayload('home'),
            'away_starting_pitcher_forecast' => $this->startingPitcherForecastPayload('away'),
            'team_stats' => TeamStatResource::collection($this->whenLoaded('teamStats')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function startingPitcherForecastPayload(string $side): ?array
    {
        $relation = $side === 'home' ? 'homeStartingPitcherForecast' : 'awayStartingPitcherForecast';
        if (! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $forecast = $this->resource->getRelation($relation);
        if (! $forecast) {
            return null;
        }

        return [
            'id' => $forecast->id,
            'forecast_hash' => $forecast->forecast_hash,
            'model_version' => $forecast->model_version,
            'prediction_source' => $forecast->prediction_source,
            'predicted_pitcher' => PlayerResource::make($forecast->whenLoaded('predictedPitcher')),
            'predicted_pitcher_rating' => $forecast->predicted_pitcher_rating,
            'predicted_rating_source' => $forecast->predicted_rating_source,
            'confidence' => $forecast->confidence,
            'evidence' => $forecast->evidence,
            'forecasted_at' => $forecast->forecasted_at?->toIso8601String(),
            'game_start_at' => $forecast->game_start_at?->toIso8601String(),
            'known_before_game_start' => $forecast->known_before_game_start,
            'actual_pitcher' => PlayerResource::make($forecast->whenLoaded('actualPitcher')),
            'actual_pitcher_rating' => $forecast->actual_pitcher_rating,
            'confirmation_source' => $forecast->confirmation_source,
            'confirmed_at' => $forecast->confirmed_at?->toIso8601String(),
            'is_correct' => $forecast->is_correct,
            'starter_changed' => $forecast->starter_changed,
            'confidence_error' => $forecast->confidence_error,
            'brier_score' => $forecast->brier_score,
            'log_loss' => $forecast->log_loss,
            'rating_difference' => $forecast->rating_difference,
            'grade' => $forecast->grade,
            'graded_at' => $forecast->graded_at?->toIso8601String(),
        ];
    }
}
