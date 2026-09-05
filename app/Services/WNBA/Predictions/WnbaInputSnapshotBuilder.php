<?php

namespace App\Services\WNBA\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\SportEvent;
use App\Models\WNBA\Game;
use App\Models\WNBA\PlayerInjury;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WnbaInputSnapshotBuilder implements EventInputSnapshotBuilder
{
    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        if ($event->sport !== 'wnba' || $release->sport !== 'wnba' || $release->phase !== 'pregame') {
            throw new PredictionLifecycleException('The WNBA snapshot builder only accepts WNBA pregame events and releases.');
        }

        $game = $event->wnbaGame()->with(['homeTeam', 'awayTeam'])->first();

        if (! $game instanceof Game || ! $game->homeTeam instanceof Team || ! $game->awayTeam instanceof Team) {
            throw new PredictionLifecycleException('WNBA prediction inputs require a linked game and both teams.');
        }

        if (! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            throw new PredictionLifecycleException('WNBA pregame predictions require a scheduled or delayed game.');
        }

        $capturedAt = now()->toImmutable();
        $cutoffAt = $event->starts_at?->toImmutable();

        if (! $cutoffAt instanceof CarbonImmutable) {
            throw new PredictionLifecycleException('WNBA pregame predictions require a canonical event start time.');
        }

        $homeMetric = $this->metricFor($game, $game->homeTeam, $capturedAt, $cutoffAt, $release);
        $awayMetric = $this->metricFor($game, $game->awayTeam, $capturedAt, $cutoffAt, $release);
        $homeInjuries = $this->injuriesFor($game->homeTeam, $capturedAt);
        $awayInjuries = $this->injuriesFor($game->awayTeam, $capturedAt);
        $sourceTimestamps = $this->sourceTimestamps(
            $event,
            $game,
            $game->homeTeam,
            $game->awayTeam,
            $homeMetric,
            $awayMetric,
            $homeInjuries,
            $awayInjuries,
        );

        return new EventInputSnapshotData(
            schemaVersion: WnbaCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            inputs: [
                'event' => [
                    'sport_event_id' => $event->public_id,
                    'game_id' => $game->getKey(),
                    'season' => (int) $game->season,
                    'season_type' => (string) $game->season_type,
                    'starts_at' => $cutoffAt->toIso8601String(),
                    'neutral_site' => (bool) $event->neutral_site,
                ],
                'home' => $this->teamInputs($game->homeTeam, $homeMetric, $homeInjuries, $release),
                'away' => $this->teamInputs($game->awayTeam, $awayMetric, $awayInjuries, $release),
            ],
            capturedAt: $capturedAt,
            cutoffAt: $cutoffAt,
            latestSourceAvailableAt: $this->latestTimestamp($sourceTimestamps),
            sourceTimestamps: $sourceTimestamps,
            pregameSafetyStatus: 'verified',
            metadata: [
                'builder' => self::class,
                'point_in_time_policy' => 'captured_before_event_start',
                'excluded_unversioned_inputs' => ['legacy_prediction', 'read_time_betting_value'],
            ],
        );
    }

    private function metricFor(
        Game $game,
        Team $team,
        CarbonImmutable $capturedAt,
        CarbonImmutable $cutoffAt,
        CalculationReleaseData $release,
    ): ?TeamMetric {
        $query = TeamMetric::query()
            ->where('team_id', $team->getKey())
            ->where('season', $game->season)
            ->where('updated_at', '<=', $capturedAt)
            ->whereDate('calculation_date', '<=', $cutoffAt->toDateString());

        if (filled($game->season_type)) {
            $query->where('season_type', (string) $game->season_type);
        }

        $metric = $query->orderByDesc('calculation_date')->orderByDesc('id')->first();

        if ($metric !== null || ! data_get($release->configuration, 'inputs.use_previous_season_metrics_fallback', false)) {
            return $metric;
        }

        return TeamMetric::query()
            ->where('team_id', $team->getKey())
            ->where('season', '<', $game->season)
            ->where('updated_at', '<=', $capturedAt)
            ->orderByDesc('season')
            ->orderByDesc('calculation_date')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, PlayerInjury> */
    private function injuriesFor(Team $team, CarbonImmutable $capturedAt): Collection
    {
        return PlayerInjury::query()
            ->where('team_id', $team->getKey())
            ->where('is_active', true)
            ->where('updated_at', '<=', $capturedAt)
            ->where(function ($query) use ($capturedAt): void {
                $query->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $capturedAt);
            })
            ->where(function ($query) use ($capturedAt): void {
                $query->whereNull('return_date')->orWhereDate('return_date', '>=', $capturedAt->toDateString());
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, PlayerInjury>  $injuries
     * @return array<string, mixed>
     */
    private function teamInputs(
        Team $team,
        ?TeamMetric $metric,
        Collection $injuries,
        CalculationReleaseData $release,
    ): array {
        return [
            'team_id' => (int) $team->getKey(),
            'name' => $team->display_name ?: trim("{$team->location} {$team->name}"),
            'elo' => (float) ($team->elo_rating ?? data_get($release->configuration, 'elo.default', 1500)),
            'metrics' => $metric === null ? null : [
                'record_season' => (int) $metric->season,
                'wins' => (int) $metric->wins,
                'losses' => (int) $metric->losses,
                'offensive_efficiency' => (float) $metric->offensive_efficiency,
                'defensive_efficiency' => (float) $metric->defensive_efficiency,
                'net_rating' => (float) $metric->net_rating,
                'tempo' => (float) $metric->tempo,
                'recent_form_rating' => (float) ($metric->recent_form_rating ?? 0),
                'injury_adjusted_team_rating' => $metric->injury_adjusted_team_rating !== null
                    ? (float) $metric->injury_adjusted_team_rating
                    : null,
                'injury_total_adjustment' => $metric->injury_total_adjustment !== null
                    ? (float) $metric->injury_total_adjustment
                    : null,
                'rest_travel_fatigue' => (float) ($metric->rest_travel_fatigue ?? 0),
            ],
            'injuries' => $injuries->map(fn (PlayerInjury $injury): array => [
                'player_id' => (int) $injury->player_id,
                'status' => strtolower((string) $injury->status),
                'type' => $injury->type,
                'detail' => $injury->detail,
                'source_updated_at' => $injury->source_updated_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, PlayerInjury>  $homeInjuries
     * @param  Collection<int, PlayerInjury>  $awayInjuries
     * @return array<string, string|null>
     */
    private function sourceTimestamps(
        SportEvent $event,
        Game $game,
        Team $homeTeam,
        Team $awayTeam,
        ?TeamMetric $homeMetric,
        ?TeamMetric $awayMetric,
        Collection $homeInjuries,
        Collection $awayInjuries,
    ): array {
        return [
            'sport_event' => $event->updated_at?->toIso8601String(),
            'game' => $game->updated_at?->toIso8601String(),
            'odds' => $game->odds_updated_at?->toIso8601String(),
            'home_team' => $homeTeam->updated_at?->toIso8601String(),
            'away_team' => $awayTeam->updated_at?->toIso8601String(),
            'home_metric' => $homeMetric?->updated_at?->toIso8601String(),
            'away_metric' => $awayMetric?->updated_at?->toIso8601String(),
            'home_injuries' => $this->latestInjuryTimestamp($homeInjuries),
            'away_injuries' => $this->latestInjuryTimestamp($awayInjuries),
        ];
    }

    /** @param Collection<int, PlayerInjury> $injuries */
    private function latestInjuryTimestamp(Collection $injuries): ?string
    {
        return $injuries
            ->map(fn (PlayerInjury $injury): mixed => $injury->source_updated_at ?? $injury->updated_at)
            ->filter()
            ->sortDesc()
            ->first()?->toIso8601String();
    }

    /** @param array<string, string|null> $timestamps */
    private function latestTimestamp(array $timestamps): ?CarbonImmutable
    {
        $latest = collect($timestamps)->filter()->sortDesc()->first();

        return is_string($latest) ? CarbonImmutable::parse($latest) : null;
    }
}
