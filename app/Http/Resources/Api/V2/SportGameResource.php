<?php

namespace App\Http\Resources\Api\V2;

use App\Models\MLB\Game;
use App\Services\Api\V2\SportContext;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class SportGameResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly SportContext $context,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dateTime = GameDateTimePresenter::forSport(
            $this->context->slug,
            $this->game_date ?? null,
            $this->game_time ?? null,
        );

        return [
            'id' => $this->id,
            'sport' => $this->context->slug,
            'espn_id' => $this->espn_id ?? $this->espn_event_id ?? null,
            'espn_event_id' => $this->espn_event_id ?? $this->espn_id ?? null,
            'espn_uid' => $this->espn_uid ?? null,
            'season' => $this->season ?? null,
            'season_type' => $this->season_type ?? null,
            'week' => $this->week ?? null,
            'postseason_round' => $this->postseason_round ?? null,
            'name' => $this->name ?? null,
            'short_name' => $this->short_name ?? null,
            'game_date' => $dateTime['game_date'],
            'game_time' => $dateTime['game_time'],
            'venue' => $this->venue_name ?? $this->venue ?? null,
            'venue_name' => $this->venue_name ?? $this->venue ?? null,
            'venue_city' => $this->venue_city ?? null,
            'venue_state' => $this->venue_state ?? null,
            'attendance' => $this->attendance ?? null,
            'status' => $this->status ?? null,
            'period' => $this->period ?? null,
            'clock' => $this->game_clock ?? $this->clock ?? null,
            'game_clock' => $this->game_clock ?? $this->clock ?? null,
            'home_team_id' => $this->home_team_id ?? null,
            'away_team_id' => $this->away_team_id ?? null,
            'home_score' => $this->home_score ?? null,
            'away_score' => $this->away_score ?? null,
            'home_linescores' => $this->home_linescores ?? null,
            'away_linescores' => $this->away_linescores ?? null,
            'broadcast_networks' => $this->broadcast_networks ?? null,
            'inning' => $this->inning ?? null,
            'inning_half' => $this->inning_half ?? null,
            'balls' => $this->balls ?? null,
            'strikes' => $this->strikes ?? null,
            'outs' => $this->outs ?? null,
            'probable_home_pitcher_espn_id' => $this->probable_home_pitcher_espn_id ?? null,
            'probable_away_pitcher_espn_id' => $this->probable_away_pitcher_espn_id ?? null,
            'actual_home_pitcher_espn_id' => $this->actual_home_pitcher_espn_id ?? null,
            'actual_away_pitcher_espn_id' => $this->actual_away_pitcher_espn_id ?? null,
            'projected_home_pitcher_espn_id' => $this->projected_home_pitcher_espn_id ?? null,
            'projected_away_pitcher_espn_id' => $this->projected_away_pitcher_espn_id ?? null,
            'home_starting_pitcher_source' => $this->resource instanceof Game
                ? $this->resource->startingPitcherSource('home')
                : null,
            'away_starting_pitcher_source' => $this->resource instanceof Game
                ? $this->resource->startingPitcherSource('away')
                : null,
            'home_starting_pitcher_confidence' => $this->resource instanceof Game
                ? $this->resource->startingPitcherConfidence('home')
                : null,
            'away_starting_pitcher_confidence' => $this->resource instanceof Game
                ? $this->resource->startingPitcherConfidence('away')
                : null,
            'home_starting_pitcher_candidates' => $this->resource instanceof Game
                ? $this->resource->startingPitcherCandidates('home')
                : [],
            'away_starting_pitcher_candidates' => $this->resource instanceof Game
                ? $this->resource->startingPitcherCandidates('away')
                : [],
            'home_expected_starting_pitcher_rating' => $this->resource instanceof Game
                ? $this->resource->expectedStartingPitcherRating('home')
                : null,
            'away_expected_starting_pitcher_rating' => $this->resource instanceof Game
                ? $this->resource->expectedStartingPitcherRating('away')
                : null,
            'home_starting_pitcher_uncertainty' => $this->resource instanceof Game
                ? $this->resource->startingPitcherUncertainty('home')
                : null,
            'away_starting_pitcher_uncertainty' => $this->resource instanceof Game
                ? $this->resource->startingPitcherUncertainty('away')
                : null,
            'pitcher_projection_metadata' => $this->pitcher_projection_metadata ?? null,
            'pitcher_projection_generated_at' => $this->serializeDateValue($this->pitcher_projection_generated_at ?? null),
            'starting_pitcher_confirmation_metadata' => $this->starting_pitcher_confirmation_metadata ?? null,
            'starting_pitchers_confirmed_at' => $this->serializeDateValue($this->starting_pitchers_confirmed_at ?? null),
            'is_ncaa_tournament' => (bool) ($this->is_ncaa_tournament ?? false),
            'tournament_id' => $this->tournament_id ?? null,
            'tournament_note' => $this->tournament_note ?? null,
            'tournament_round' => $this->tournament_round ?? null,
            'tournament_region' => $this->tournament_region ?? null,
            'home_seed' => $this->home_seed ?? null,
            'away_seed' => $this->away_seed ?? null,
            'play_in_target_seed' => $this->play_in_target_seed ?? null,
            'matchup_context' => $this->resource->getAttribute('matchup_context'),
            'home_team' => $this->teamPayload($this->whenLoaded('homeTeam')),
            'away_team' => $this->teamPayload($this->whenLoaded('awayTeam')),
            'home_starting_pitcher' => $this->resolvedPitcherPayload('home'),
            'away_starting_pitcher' => $this->resolvedPitcherPayload('away'),
            'home_starting_pitcher_forecast' => $this->startingPitcherForecastPayload('home'),
            'away_starting_pitcher_forecast' => $this->startingPitcherForecastPayload('away'),
            'has_prediction' => $this->relationLoaded('prediction') && $this->prediction !== null,
            'completed_at' => $this->serializeDateValue($this->completed_at ?? null),
            'updated_at' => $this->serializeDateValue($this->updated_at ?? null),
        ];
    }

    private function serializeDateValue(mixed $value): ?string
    {
        return GameDateTimePresenter::serializeDateValue($value);
    }

    private function serializeTimeValue(mixed $value): ?string
    {
        return GameDateTimePresenter::timeString($value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function teamPayload(mixed $team): ?array
    {
        if (! $team || $team instanceof MissingValue) {
            return null;
        }

        return [
            'id' => $team->id,
            'espn_id' => $team->espn_id ?? null,
            'abbreviation' => $team->abbreviation ?? null,
            'location' => $team->location ?? $team->school ?? null,
            'name' => $team->name ?? $team->mascot ?? null,
            'nickname' => $team->nickname ?? null,
            'display_name' => $team->display_name ?? $this->displayName($team),
            'short_display_name' => $team->short_display_name ?? $team->abbreviation ?? null,
            'conference' => $team->conference ?? null,
            'league' => $team->league ?? null,
            'division' => $team->division ?? null,
            'color' => $team->color ?? null,
            'alternate_color' => $team->alternate_color ?? null,
            'logo' => $team->logo_url ?? null,
            'logo_url' => $team->logo_url ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function playerPayload(mixed $player): ?array
    {
        if (! $player || $player instanceof MissingValue) {
            return null;
        }

        return [
            'id' => $player->id,
            'espn_id' => $player->espn_id ?? null,
            'full_name' => $player->full_name ?? $player->display_name ?? trim((string) ($player->first_name ?? '').' '.(string) ($player->last_name ?? '')),
            'display_name' => $player->display_name ?? $player->full_name ?? null,
            'headshot_url' => $player->headshot_url ?? null,
            'position' => $player->position ?? null,
            'elo_rating' => $player->elo_rating ?? null,
        ];
    }

    private function resolvedPitcherPayload(string $side): ?array
    {
        if (! $this->resource instanceof Game) {
            return null;
        }

        $probableRelation = $side === 'home' ? 'probableHomePitcher' : 'probableAwayPitcher';
        $actualRelation = $side === 'home' ? 'actualHomePitcher' : 'actualAwayPitcher';
        $projectedRelation = $side === 'home' ? 'projectedHomePitcher' : 'projectedAwayPitcher';

        if ($this->resource->relationLoaded($actualRelation) && $this->resource->getRelation($actualRelation)) {
            return $this->playerPayload($this->resource->getRelation($actualRelation));
        }

        if ($this->resource->relationLoaded($probableRelation) && $this->resource->getRelation($probableRelation)) {
            return $this->playerPayload($this->resource->getRelation($probableRelation));
        }

        if ($this->resource->relationLoaded($projectedRelation) && $this->resource->getRelation($projectedRelation)) {
            return $this->playerPayload($this->resource->getRelation($projectedRelation));
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function startingPitcherForecastPayload(string $side): ?array
    {
        if (! $this->resource instanceof Game) {
            return null;
        }

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
            'predicted_pitcher' => $this->playerPayload($forecast->relationLoaded('predictedPitcher') ? $forecast->predictedPitcher : null),
            'predicted_pitcher_rating' => $forecast->predicted_pitcher_rating,
            'predicted_rating_source' => $forecast->predicted_rating_source,
            'confidence' => $forecast->confidence,
            'evidence' => $forecast->evidence,
            'forecasted_at' => $this->serializeDateValue($forecast->forecasted_at),
            'game_start_at' => $this->serializeDateValue($forecast->game_start_at),
            'known_before_game_start' => $forecast->known_before_game_start,
            'actual_pitcher' => $this->playerPayload($forecast->relationLoaded('actualPitcher') ? $forecast->actualPitcher : null),
            'actual_pitcher_rating' => $forecast->actual_pitcher_rating,
            'confirmation_source' => $forecast->confirmation_source,
            'confirmed_at' => $this->serializeDateValue($forecast->confirmed_at),
            'is_correct' => $forecast->is_correct,
            'starter_changed' => $forecast->starter_changed,
            'confidence_error' => $forecast->confidence_error,
            'brier_score' => $forecast->brier_score,
            'log_loss' => $forecast->log_loss,
            'rating_difference' => $forecast->rating_difference,
            'grade' => $forecast->grade,
            'graded_at' => $this->serializeDateValue($forecast->graded_at),
        ];
    }

    private function displayName(mixed $team): ?string
    {
        $parts = array_filter([
            $team->location ?? $team->school ?? null,
            $team->name ?? $team->nickname ?? $team->mascot ?? null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
