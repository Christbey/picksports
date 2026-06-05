<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportPredictionResource extends JsonResource
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
        return [
            'id' => $this->attribute('id'),
            'sport' => $this->context->slug,
            'game_id' => $this->attribute('game_id'),
            'home_team_id' => $this->gameAttribute('home_team_id'),
            'away_team_id' => $this->gameAttribute('away_team_id'),
            'game' => $this->game(),
            'status' => $this->gameAttribute('status'),
            'pick' => $this->pick(),
            'projection' => $this->projection(),
            'home_win_probability' => $this->homeWinProbability(),
            'away_win_probability' => $this->awayWinProbability(),
            'win_probability' => $this->homeWinProbability(),
            'predicted_spread' => $this->floatAttribute('predicted_spread'),
            'predicted_total' => $this->floatAttribute('predicted_total'),
            'confidence_score' => $this->floatAttribute('confidence_score'),
            'confidence_level' => $this->confidenceLevel(),
            'home_elo' => $this->floatAttribute('home_elo'),
            'away_elo' => $this->floatAttribute('away_elo'),
            'home_team_elo' => $this->floatAttribute('home_team_elo'),
            'away_team_elo' => $this->floatAttribute('away_team_elo'),
            'home_pitcher_elo' => $this->floatAttribute('home_pitcher_elo'),
            'away_pitcher_elo' => $this->floatAttribute('away_pitcher_elo'),
            'home_combined_elo' => $this->floatAttribute('home_combined_elo'),
            'away_combined_elo' => $this->floatAttribute('away_combined_elo'),
            'actual_spread' => $this->floatAttribute('actual_spread'),
            'actual_total' => $this->floatAttribute('actual_total'),
            'spread_error' => $this->floatAttribute('spread_error'),
            'total_error' => $this->floatAttribute('total_error'),
            'winner_correct' => $this->attribute('winner_correct'),
            'graded_at' => $this->serializeDateValue($this->attribute('graded_at')),
            'live_predicted_spread' => $this->liveFloatAttribute('live_predicted_spread'),
            'live_predicted_total' => $this->liveFloatAttribute('live_predicted_total'),
            'live_win_probability' => $this->liveFloatAttribute('live_win_probability'),
            'live_seconds_remaining' => $this->liveAttribute('live_seconds_remaining'),
            'live_outs_remaining' => $this->liveAttribute('live_outs_remaining'),
            'live_updated_at' => $this->serializeDateValue($this->liveAttribute('live_updated_at')),
            'depth_chart_context' => $this->depthChartContext(),
            'market_summary' => $this->marketSummary(),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function game(): ?array
    {
        $game = $this->relation('game');

        if (! $game) {
            return null;
        }

        return [
            'id' => $game->getAttribute('id'),
            'espn_id' => $game->getAttribute('espn_id'),
            'season' => $game->getAttribute('season'),
            'season_type' => $game->getAttribute('season_type'),
            'week' => $game->getAttribute('week'),
            'name' => $game->getAttribute('name'),
            'short_name' => $game->getAttribute('short_name'),
            'game_date' => $this->serializeDateValue($game->getAttribute('game_date')),
            'game_time' => $this->serializeDateValue($game->getAttribute('game_time')),
            'status' => $game->getAttribute('status'),
            'home_team_id' => $game->getAttribute('home_team_id'),
            'away_team_id' => $game->getAttribute('away_team_id'),
            'home_team' => $this->team($game, 'homeTeam'),
            'away_team' => $this->team($game, 'awayTeam'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pick(): array
    {
        $winProbability = $this->floatAttribute('win_probability');
        $game = $this->relation('game');
        $side = $winProbability === null ? null : ($winProbability >= 0.5 ? 'home' : 'away');
        $team = $game && $side ? $this->teamModel($game, "{$side}Team") : null;

        return [
            'side' => $side,
            'team_id' => $team?->getAttribute('id') ?? ($game?->getAttribute("{$side}_team_id")),
            'team_abbreviation' => $team?->getAttribute('abbreviation'),
            'label' => $team?->getAttribute('abbreviation') ?? $team?->getAttribute('display_name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(): array
    {
        return [
            'home_win_probability' => $this->homeWinProbability(),
            'away_win_probability' => $this->awayWinProbability(),
            'predicted_spread' => $this->floatAttribute('predicted_spread'),
            'predicted_total' => $this->floatAttribute('predicted_total'),
            'confidence_score' => $this->floatAttribute('confidence_score'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marketSummary(): array
    {
        $hasSpread = $this->attribute('vegas_spread') !== null;

        return [
            'has_odds' => $hasSpread,
            'markets' => $hasSpread ? ['spread'] : [],
            'odds_updated_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(Model $game, string $relation): ?array
    {
        $team = $this->teamModel($game, $relation);

        if (! $team) {
            return null;
        }

        return [
            'id' => $team->getAttribute('id'),
            'abbreviation' => $team->getAttribute('abbreviation'),
            'display_name' => $team->getAttribute('display_name')
                ?? trim((string) ($team->getAttribute('location') ?? $team->getAttribute('school')).' '.(string) ($team->getAttribute('name') ?? $team->getAttribute('mascot'))),
            'logo_url' => $team->getAttribute('logo_url'),
        ];
    }

    private function teamModel(Model $game, string $relation): ?Model
    {
        if (! $game->relationLoaded($relation)) {
            return null;
        }

        $team = $game->getRelation($relation);

        return $team instanceof Model ? $team : null;
    }

    private function relation(string $relation): ?Model
    {
        if (! $this->resource instanceof Model || ! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $related = $this->resource->getRelation($relation);

        return $related instanceof Model ? $related : null;
    }

    private function gameAttribute(string $key): mixed
    {
        return $this->relation('game')?->getAttribute($key);
    }

    private function homeWinProbability(): ?float
    {
        $winProbability = $this->floatAttribute('win_probability');

        return $winProbability === null ? null : round($winProbability, 3);
    }

    private function awayWinProbability(): ?float
    {
        $homeWinProbability = $this->homeWinProbability();

        return $homeWinProbability === null ? null : round(1 - $homeWinProbability, 3);
    }

    private function confidenceLevel(): string
    {
        $confidenceScore = $this->floatAttribute('confidence_score');

        if ($confidenceScore === null) {
            return 'unavailable';
        }

        return match (true) {
            $confidenceScore >= 75 => 'high',
            $confidenceScore >= 60 => 'medium',
            default => 'low',
        };
    }

    private function isLivePrediction(): bool
    {
        $status = $this->gameAttribute('status');

        return $status === null || in_array($status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true);
    }

    private function liveAttribute(string $key): mixed
    {
        return $this->isLivePrediction() ? $this->attribute($key) : null;
    }

    private function liveFloatAttribute(string $key): ?float
    {
        $value = $this->liveAttribute($key);

        return $value === null ? null : (float) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function depthChartContext(): ?array
    {
        $metadata = is_array($this->attribute('model_metadata')) ? $this->attribute('model_metadata') : [];

        if (is_array($metadata['depth_chart_injuries'] ?? null)) {
            $injuries = $metadata['depth_chart_injuries'];

            return [
                'type' => 'injury_weighting',
                'applied' => (bool) ($injuries['applied'] ?? false),
                'home_out_weighted' => (float) ($injuries['home_out_weighted'] ?? 0.0),
                'away_out_weighted' => (float) ($injuries['away_out_weighted'] ?? 0.0),
                'home_questionable_weighted' => (float) ($injuries['home_questionable_weighted'] ?? 0.0),
                'away_questionable_weighted' => (float) ($injuries['away_questionable_weighted'] ?? 0.0),
                'spread_adjustment' => (float) ($injuries['spread_adjustment'] ?? 0.0),
                'total_adjustment' => (float) ($injuries['total_adjustment'] ?? 0.0),
                'win_probability_adjustment' => isset($injuries['win_probability_adjustment'])
                    ? (float) $injuries['win_probability_adjustment']
                    : null,
                'injury_model_source' => $metadata['injury_model_source'] ?? null,
                'injury_spread_model_source' => $metadata['injury_spread_model_source'] ?? null,
                'injury_total_model_source' => $metadata['injury_total_model_source'] ?? null,
            ];
        }

        if (is_array($metadata['depth_chart_context'] ?? null)) {
            $source = $metadata['depth_chart_context'];

            return [
                'type' => 'starter_fallback',
                'home_pitcher_source' => $source['home_pitcher_source'] ?? null,
                'away_pitcher_source' => $source['away_pitcher_source'] ?? null,
                'home_depth_chart_fallback_used' => (bool) ($source['home_depth_chart_fallback_used'] ?? false),
                'away_depth_chart_fallback_used' => (bool) ($source['away_depth_chart_fallback_used'] ?? false),
                'probable_pitcher_injury_applied' => (bool) ($source['probable_pitcher_injury_applied'] ?? false),
            ];
        }

        return null;
    }

    private function attribute(string $key): mixed
    {
        if (! $this->resource instanceof Model) {
            return null;
        }

        return array_key_exists($key, $this->resource->getAttributes())
            ? $this->resource->getAttribute($key)
            : null;
    }

    private function floatAttribute(string $key): ?float
    {
        $value = $this->attribute($key);

        return $value === null ? null : (float) $value;
    }

    private function serializeDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
