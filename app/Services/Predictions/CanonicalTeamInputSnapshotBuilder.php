<?php

namespace App\Services\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\SportEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class CanonicalTeamInputSnapshotBuilder implements EventInputSnapshotBuilder
{
    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $sport = $this->sport();

        if ($event->sport !== $sport || $release->sport !== $sport || $release->phase !== 'pregame') {
            throw new PredictionLifecycleException(strtoupper($sport).' snapshot builder received an incompatible event or release.');
        }

        $relation = $this->gameRelation();
        $game = $event->{$relation}()->with(['homeTeam', 'awayTeam'])->first();
        $gameClass = $this->gameModel();

        if (! $game instanceof $gameClass
            || ! $game->getRelation('homeTeam') instanceof Model
            || ! $game->getRelation('awayTeam') instanceof Model) {
            throw new PredictionLifecycleException(strtoupper($sport).' prediction inputs require a linked game and both teams.');
        }

        if (! in_array($game->getAttribute('status'), ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            throw new PredictionLifecycleException(strtoupper($sport).' pregame predictions require a scheduled or delayed game.');
        }

        $capturedAt = now()->toImmutable();
        $cutoffAt = $event->starts_at?->toImmutable();

        if (! $cutoffAt instanceof CarbonImmutable) {
            throw new PredictionLifecycleException(strtoupper($sport).' pregame predictions require a canonical event start time.');
        }

        $homeTeam = $game->getRelation('homeTeam');
        $awayTeam = $game->getRelation('awayTeam');
        $homeMetric = $this->metricFor($game, $homeTeam, $capturedAt, $cutoffAt, $release);
        $awayMetric = $this->metricFor($game, $awayTeam, $capturedAt, $cutoffAt, $release);
        $homeInjuries = $this->injuriesFor($homeTeam, $capturedAt);
        $awayInjuries = $this->injuriesFor($awayTeam, $capturedAt);
        $additionalInputs = $this->additionalInputs($event, $game, $capturedAt, $cutoffAt, $release);
        $sourceTimestamps = $this->sourceTimestamps(
            $event,
            $game,
            $homeTeam,
            $awayTeam,
            $homeMetric,
            $awayMetric,
            $homeInjuries,
            $awayInjuries,
        );

        return new EventInputSnapshotData(
            schemaVersion: $this->inputSchemaVersion(),
            inputs: [
                'event' => [
                    'sport_event_id' => $event->public_id,
                    'game_id' => $game->getKey(),
                    'season' => (int) $game->getAttribute('season'),
                    'season_type' => (string) $game->getAttribute('season_type'),
                    'starts_at' => $cutoffAt->toIso8601String(),
                    'neutral_site' => (bool) $event->neutral_site,
                ],
                'home' => $this->teamInputs($homeTeam, $homeMetric, $homeInjuries, $release),
                'away' => $this->teamInputs($awayTeam, $awayMetric, $awayInjuries, $release),
                ...$additionalInputs,
            ],
            capturedAt: $capturedAt,
            cutoffAt: $cutoffAt,
            latestSourceAvailableAt: $this->latestTimestamp($sourceTimestamps),
            sourceTimestamps: $sourceTimestamps,
            pregameSafetyStatus: 'verified',
            metadata: [
                'builder' => static::class,
                'point_in_time_policy' => 'captured_before_event_start',
                'excluded_unversioned_inputs' => [
                    'legacy_prediction',
                    'read_time_betting_value',
                    'mutable_market_blend',
                ],
            ],
        );
    }

    private function metricFor(
        Model $game,
        Model $team,
        CarbonImmutable $capturedAt,
        CarbonImmutable $cutoffAt,
        CalculationReleaseData $release,
    ): ?Model {
        $metricClass = $this->teamMetricModel();
        $query = $metricClass::query()
            ->where('team_id', $team->getKey())
            ->where('season', $game->getAttribute('season'))
            ->where('updated_at', '<=', $capturedAt)
            ->whereDate('calculation_date', '<=', $cutoffAt->toDateString());

        if ($this->teamMetricsUseSeasonType() && filled($game->getAttribute('season_type'))) {
            $query->where('season_type', (string) $game->getAttribute('season_type'));
        }

        $metric = $query->orderByDesc('calculation_date')->orderByDesc('id')->first();

        if ($metric !== null || ! data_get($release->configuration, 'inputs.use_previous_season_metrics_fallback', false)) {
            return $metric;
        }

        return $metricClass::query()
            ->where('team_id', $team->getKey())
            ->where('season', '<', $game->getAttribute('season'))
            ->where('updated_at', '<=', $capturedAt)
            ->orderByDesc('season')
            ->orderByDesc('calculation_date')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, Model> */
    private function injuriesFor(Model $team, CarbonImmutable $capturedAt): Collection
    {
        $injuryClass = $this->playerInjuryModel();

        return $injuryClass::query()
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

    /** @param Collection<int, Model> $injuries @return array<string, mixed> */
    private function teamInputs(
        Model $team,
        ?Model $metric,
        Collection $injuries,
        CalculationReleaseData $release,
    ): array {
        $displayName = $team->getAttribute('display_name')
            ?: trim(implode(' ', array_filter([
                $team->getAttribute('location') ?: $team->getAttribute('school'),
                $team->getAttribute('name') ?: $team->getAttribute('mascot'),
            ])));

        return [
            'team_id' => (int) $team->getKey(),
            'name' => $displayName,
            'elo' => (float) ($team->getAttribute('elo_rating') ?? data_get($release->configuration, 'elo.default', 1500)),
            'metrics' => $metric === null ? null : $this->metricInputs($metric),
            'injuries' => $injuries->map(fn (Model $injury): array => [
                'player_id' => (int) $injury->getAttribute('player_id'),
                'status' => strtolower((string) $injury->getAttribute('status')),
                'type' => $injury->getAttribute('type'),
                'detail' => $injury->getAttribute('detail'),
                'source_updated_at' => $injury->getAttribute('source_updated_at')?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @param Collection<int, Model> $homeInjuries @param Collection<int, Model> $awayInjuries @return array<string, string|null> */
    private function sourceTimestamps(
        SportEvent $event,
        Model $game,
        Model $homeTeam,
        Model $awayTeam,
        ?Model $homeMetric,
        ?Model $awayMetric,
        Collection $homeInjuries,
        Collection $awayInjuries,
    ): array {
        return [
            'sport_event' => $event->updated_at?->toIso8601String(),
            'game' => $game->updated_at?->toIso8601String(),
            'odds' => $game->getAttribute('odds_updated_at')?->toIso8601String(),
            'home_team' => $homeTeam->updated_at?->toIso8601String(),
            'away_team' => $awayTeam->updated_at?->toIso8601String(),
            'home_metric' => $homeMetric?->updated_at?->toIso8601String(),
            'away_metric' => $awayMetric?->updated_at?->toIso8601String(),
            'home_injuries' => $this->latestInjuryTimestamp($homeInjuries),
            'away_injuries' => $this->latestInjuryTimestamp($awayInjuries),
            ...$this->additionalSourceTimestamps($event, $game),
        ];
    }

    /** @param Collection<int, Model> $injuries */
    private function latestInjuryTimestamp(Collection $injuries): ?string
    {
        return $injuries
            ->map(fn (Model $injury): mixed => $injury->getAttribute('source_updated_at') ?? $injury->updated_at)
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

    protected function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    abstract protected function sport(): string;

    abstract protected function inputSchemaVersion(): string;

    abstract protected function gameRelation(): string;

    /** @return class-string<Model> */
    abstract protected function gameModel(): string;

    /** @return class-string<Model> */
    abstract protected function teamMetricModel(): string;

    /** @return class-string<Model> */
    abstract protected function playerInjuryModel(): string;

    /** @return array<string, mixed> */
    abstract protected function metricInputs(Model $metric): array;

    protected function teamMetricsUseSeasonType(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function additionalInputs(
        SportEvent $event,
        Model $game,
        CarbonImmutable $capturedAt,
        CarbonImmutable $cutoffAt,
        CalculationReleaseData $release,
    ): array {
        return [];
    }

    /** @return array<string, string|null> */
    protected function additionalSourceTimestamps(SportEvent $event, Model $game): array
    {
        return [];
    }
}
