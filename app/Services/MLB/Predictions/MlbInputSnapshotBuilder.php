<?php

namespace App\Services\MLB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Models\MLB\Game;
use App\Models\MLB\GameWeather;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerInjury;
use App\Models\MLB\TeamMetric;
use App\Models\SportEvent;
use App\Services\Predictions\CanonicalTeamInputSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class MlbInputSnapshotBuilder extends CanonicalTeamInputSnapshotBuilder
{
    private ?GameWeather $capturedWeather = null;

    /** @var array<string,Player|null> */
    private array $capturedPitchers = [];

    protected function sport(): string
    {
        return 'mlb';
    }

    protected function inputSchemaVersion(): string
    {
        return MlbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'mlbGame';
    }

    /** @return class-string<Model> */
    protected function gameModel(): string
    {
        return Game::class;
    }

    /** @return class-string<Model> */
    protected function teamMetricModel(): string
    {
        return TeamMetric::class;
    }

    /** @return class-string<Model> */
    protected function playerInjuryModel(): string
    {
        return PlayerInjury::class;
    }

    /** @return array<string,mixed> */
    protected function metricInputs(Model $metric): array
    {
        return [
            'record_season' => (int) $metric->getAttribute('season'), 'wins' => (int) $metric->getAttribute('wins'),
            'losses' => (int) $metric->getAttribute('losses'), 'runs_per_game' => (float) ($metric->getAttribute('runs_per_game') ?? 0),
            'runs_allowed_per_game' => (float) ($metric->getAttribute('runs_allowed_per_game') ?? 0),
            'run_differential_per_game' => (float) ($metric->getAttribute('run_differential_per_game') ?? 0),
            'recent_form_rating' => (float) ($metric->getAttribute('recent_form_rating') ?? 0),
            'injury_adjusted_team_rating' => $this->nullableFloat($metric->getAttribute('injury_adjusted_team_rating')),
            'injury_total_adjustment' => $this->nullableFloat($metric->getAttribute('injury_total_adjustment')),
            'rest_travel_fatigue' => (float) ($metric->getAttribute('rest_travel_fatigue') ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    protected function additionalInputs(SportEvent $event, Model $game, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt, CalculationReleaseData $release): array
    {
        $this->capturedWeather = GameWeather::query()->where('game_id', $game->getKey())
            ->where('updated_at', '<=', $capturedAt)->where('observed_at', '<=', $capturedAt)
            ->orderByDesc('observed_at')->orderByDesc('id')->first();
        $pitching = [];
        foreach (['home', 'away'] as $side) {
            $espnId = $game instanceof Game ? $game->resolvedStartingPitcherEspnId($side) : null;
            $pitcher = filled($espnId) ? Player::query()->where('espn_id', $espnId)->where('updated_at', '<=', $capturedAt)->first() : null;
            $this->capturedPitchers[$side] = $pitcher;
            $pitching[$side] = [
                'player_id' => $pitcher?->getKey(), 'espn_id' => $espnId, 'name' => $pitcher?->full_name,
                'elo' => (float) ($pitcher?->elo_rating ?? data_get($release->configuration, 'elo.default_pitcher', 1500)),
                'source' => $game instanceof Game ? $game->startingPitcherSource($side) : null,
                'confidence' => $game instanceof Game ? $game->startingPitcherConfidence($side) : null,
            ];
        }

        return [
            'venue' => ['name' => $game->getAttribute('venue_name'), 'city' => $game->getAttribute('venue_city'), 'state' => $game->getAttribute('venue_state')],
            'pitching' => $pitching,
            'weather' => $this->capturedWeather === null ? null : [
                'observed_at' => $this->capturedWeather->observed_at?->toIso8601String(),
                'temperature_f' => $this->nullableFloat($this->capturedWeather->temperature_f),
                'wind_speed_mph' => $this->nullableFloat($this->capturedWeather->wind_speed_mph),
                'is_indoor' => (bool) $this->capturedWeather->is_indoor, 'roof_status' => $this->capturedWeather->roof_status,
            ],
        ];
    }

    /** @return array<string,string|null> */
    protected function additionalSourceTimestamps(SportEvent $event, Model $game): array
    {
        return [
            'weather' => $this->capturedWeather?->updated_at?->toIso8601String(),
            'home_pitcher' => $this->capturedPitchers['home']?->updated_at?->toIso8601String(),
            'away_pitcher' => $this->capturedPitchers['away']?->updated_at?->toIso8601String(),
        ];
    }
}
