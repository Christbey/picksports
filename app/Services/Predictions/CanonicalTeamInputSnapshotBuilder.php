<?php

namespace App\Services\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Contracts\Predictions\EventInputSnapshotBuilder;
use App\Exceptions\EloAdmissionRefused;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\MLB\Game;
use App\Models\SportEvent;
use App\Services\Sports\Elo\EloAdmissionMode;
use App\Services\Sports\Elo\EloInputAdmission;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use App\Services\Sports\Elo\EloSqlClockToken;
use App\Services\Sports\Elo\EloWriterProtocol;
use App\Support\MLB\MlbGameStart;
use App\Support\Sports\CanonicalEventStart;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

abstract class CanonicalTeamInputSnapshotBuilder implements EventInputSnapshotBuilder
{
    private ?EloSqlClockToken $activeClockToken = null;

    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        return $this->withManagedClockCapture($event, fn (): EventInputSnapshotData => $this->buildSnapshot($event, $release));
    }

    /** All existing source selectors use the default connection; mixed targets must not inherit its rows. */
    protected function withManagedClockCapture(SportEvent $event, Closure $capture): EventInputSnapshotData
    {
        $connection = $event->getConnection();
        if (! app(EloAdmissionMode::class)->managed($event->sport, $connection)) {
            return $capture();
        }
        app(EloWriterProtocol::class)->requireCapability($event->sport);
        if ($connection !== DB::connection()) {
            throw new EloAdmissionRefused('sql_clock_mixed_source_connection', [], []);
        }
        foreach ([$this->gameModel(), $this->teamMetricModel(), $this->playerInjuryModel()] as $class) {
            if ((new $class)->getConnection() !== $connection) {
                throw new EloAdmissionRefused('sql_clock_mixed_source_connection', [], []);
            }
        }
        $policy = app(EloSqlClockPolicy::class);
        $owner = $this->activeClockToken === null;
        $token = $this->activeClockToken ?? $policy->capture($connection);
        $policy->assertUnchanged($token, $connection);
        $this->activeClockToken = $token;
        try {
            $data = $capture();
            $policy->assertUnchanged($token, $event->getConnection());

            return new EventInputSnapshotData(
                schemaVersion: $data->schemaVersion, inputs: $data->inputs, capturedAt: $data->capturedAt,
                cutoffAt: $data->cutoffAt, latestSourceAvailableAt: $data->latestSourceAvailableAt,
                sourceTimestamps: $data->sourceTimestamps, pregameSafetyStatus: $data->pregameSafetyStatus,
                objectUri: $data->objectUri, metadata: [...$data->metadata, 'elo_sql_clock_policy' => EloSqlClockPolicy::policy()],
                sqlClockToken: $token,
            );
        } finally {
            if ($owner) {
                $this->activeClockToken = null;
            }
        }
    }

    private function buildSnapshot(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
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

        if ($game instanceof Game) {
            $game = CanonicalEventStart::game($event, true);
            if ($game === null) {
                throw new PredictionLifecycleException('MLB inputs require a current linked game.');
            }
            $game->load(['homeTeam', 'awayTeam']);
            if (! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
                throw new PredictionLifecycleException('MLB pregame inputs require current scheduled or delayed status.');
            }
        }
        $capturedAt = now()->toImmutable();
        $cutoffAt = ($event->sport === 'cfb' ? CanonicalEventStart::forCurrentCfbEvent($event, true) : CanonicalEventStart::forEvent($event, true));
        if ($game instanceof Game && ! MlbGameStart::observedBy($game, $capturedAt)) {
            throw new PredictionLifecycleException('MLB input capture requires an observed schedule available by capture time.');
        }

        if (! $cutoffAt instanceof CarbonImmutable) {
            throw new PredictionLifecycleException(strtoupper($sport).' pregame predictions require a canonical event start time.');
        }

        $homeTeam = $game->getRelation('homeTeam');
        $awayTeam = $game->getRelation('awayTeam');
        $eloAdmission = app(EloInputAdmission::class)->teams($sport, $game, $cutoffAt, $event);
        if ($eloAdmission !== null) {
            $capturedAt = LegacyTimestampCarrier::forInstant(CarbonImmutable::parse($eloAdmission['evidence']['admitted_at_utc']));
            if ($capturedAt === null) {
                throw new EloAdmissionRefused('input_capture_clock_unrepresentable', [], []);
            }
        }
        if ($game instanceof Game && (MlbGameStart::legacyTimestamp($capturedAt) === null
            || ! MlbGameStart::observedBy($game, $capturedAt))) {
            throw new PredictionLifecycleException('MLB canonical capture requires a lossless capture clock and schedule available by capture.');
        }
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

        if ($game instanceof Game) {
            $sourceTimestamps['mlb_scheduled_start_observed_at_utc'] = CarbonImmutable::parse($game->start_observed_at, 'UTC')->format('Y-m-d\TH:i:s.u\Z');
        }

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
                'home' => $this->teamInputs($homeTeam, $homeMetric, $homeInjuries, $release, $eloAdmission['values'][$homeTeam->getKey()] ?? null),
                'away' => $this->teamInputs($awayTeam, $awayMetric, $awayInjuries, $release, $eloAdmission['values'][$awayTeam->getKey()] ?? null),
                ...$additionalInputs,
            ],
            capturedAt: $capturedAt,
            cutoffAt: $cutoffAt,
            latestSourceAvailableAt: $this->latestTimestamp($sourceTimestamps),
            sourceTimestamps: $sourceTimestamps,
            pregameSafetyStatus: 'verified',
            metadata: [
                ...($game instanceof Game ? ['mlb_timing_policy' => MlbGameStart::TIMING_POLICY, 'mlb_start_observation' => MlbGameStart::capture($game, $capturedAt)] : []),
                ...($eloAdmission === null ? [] : ['elo_input_admission' => $eloAdmission['evidence']]),
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

    protected function metricFor(
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
            $query->whereIn('season_type', $this->teamMetricSeasonTypes($game));
        }

        $metric = $query->orderByDesc('calculation_date')->orderByDesc('id')->first();
        $usePreviousSeasonFallback = (bool) data_get(
            $release->configuration,
            'inputs.use_previous_season_metrics_fallback',
            false,
        );

        if (($metric !== null && $this->metricHasUsableSample($metric)) || ! $usePreviousSeasonFallback) {
            return $metric;
        }

        $previousSeasonMetric = $metricClass::query()
            ->where('team_id', $team->getKey())
            ->where('season', '<', $game->getAttribute('season'))
            ->where('updated_at', '<=', $capturedAt)
            ->orderByDesc('season')
            ->orderByDesc('calculation_date')
            ->orderByDesc('id')
            ->first();

        if ($previousSeasonMetric !== null) {
            return $previousSeasonMetric;
        }

        return $metric !== null && $this->metricHasFallbackUsableInputs($metric)
            ? $metric
            : null;
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
        ?string $admittedElo = null,
    ): array {
        $displayName = $team->getAttribute('display_name')
            ?: trim(implode(' ', array_filter([
                $team->getAttribute('location') ?: $team->getAttribute('school'),
                $team->getAttribute('name') ?: $team->getAttribute('mascot'),
            ])));

        return [
            'team_id' => (int) $team->getKey(),
            'name' => $displayName,
            'elo' => (float) ($admittedElo ?? $team->getAttribute('elo_rating') ?? data_get($release->configuration, 'elo.default', 1500)),
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
        $latest = null;
        foreach ($timestamps as $timestamp) {
            if (! is_string($timestamp) || ! $timestamp) {
                continue;
            }
            $candidate = CarbonImmutable::parse($timestamp);
            if ($latest === null || $candidate->gt($latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
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

    /** @return list<string> */
    protected function teamMetricSeasonTypes(Model $game): array
    {
        return [(string) $game->getAttribute('season_type')];
    }

    protected function metricHasUsableSample(Model $metric): bool
    {
        return true;
    }

    protected function metricHasFallbackUsableInputs(Model $metric): bool
    {
        return false;
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
