<?php

namespace App\Services\MLB;

use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MlbPeriodFeatureStore
{
    public const FEATURE_VERSION = 'mlb-period-live-v2-observed-start';

    public function __construct(private readonly MlbPeriodFeatureBuilder $builder) {}

    /**
     * @param  Collection<int, Game>  $games
     * @return array<int, array<string, array<string, float|int|null>>>
     */
    public function forGames(Collection $games, ?array $canonicalPredictionIds = null): array
    {
        $games = $this->validGames($games);
        if ($games->isEmpty()) {
            return [];
        }

        if ($canonicalPredictionIds !== null && ! app(MlbPeriodForecastSource::class)->usesCanonical()) {
            return [];
        }
        $features = $this->latestSnapshots($games, $canonicalPredictionIds)
            ->mapWithKeys(fn (PeriodFeatureSnapshot $snapshot): array => [
                (int) $snapshot->game_id => array_intersect_key((array) $snapshot->features, MlbPeriodFeatureBuilder::MARKETS),
            ])
            ->all();

        return $features;
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return Collection<int, PeriodFeatureSnapshot>
     */
    public function materialize(Collection $games): Collection
    {
        $games = $this->validGames($games);
        if ($games->isEmpty()) {
            return collect();
        }

        $connection = (new Game)->getConnection();
        if ($connection->transactionLevel() !== 0) {
            throw new \RuntimeException('MLB period materialization requires its own transaction and lock order.');
        }
        foreach ([new PeriodFeatureSnapshot, new SportEvent, new CanonicalPrediction] as $model) {
            if ($model->getConnection() !== $connection) {
                throw new \RuntimeException('MLB period captures require one coherent database connection.');
            }
        }
        foreach ($games as $game) {
            if ($game->getConnection() !== $connection) {
                throw new \RuntimeException('MLB period captures require one coherent database connection.');
            }
        }

        $source = app(MlbPeriodForecastSource::class);
        $canonical = $source->usesCanonical();
        if ($canonical) {
            (new ModelCollection($games->all()))->loadMissing('sportEvent:id,starts_at');
        }
        $sources = $canonical ? $source->forGames($games) : collect();
        $owners = $games->mapWithKeys(fn (Game $game): array => [(int) $game->id => [
            'event_id' => $game->sport_event_id,
            'start_context_hash' => $game->start_context_hash,
            'start' => MlbGameStart::for($game)?->format('Y-m-d\TH:i:s.uP'),
        ]]);
        $sourceIdentities = $sources->map(fn ($snapshot): array => [
            'id' => $snapshot->id, 'game_id' => $snapshot->game_id,
            'feature_hash' => $snapshot->feature_hash,
            'generated_at' => $snapshot->generated_at->format('Y-m-d\TH:i:s.uP'),
            'features_available_at' => $snapshot->features_available_at->format('Y-m-d\TH:i:s.uP'),
            'game_start_at' => $snapshot->game_start_at->format('Y-m-d\TH:i:s.uP'),
        ]);
        $eventIds = $canonical ? $owners->pluck('event_id')->filter()->unique()->sort()->values() : collect();
        $predictionIds = $canonical ? $sources->pluck('id')->unique()->sort()->values() : collect();
        $featuresByGame = $this->builder->liveFeaturesForGames($games, $canonical ? $sources : null);
        $featureVersion = $this->featureVersion();
        if ($connection->transactionLevel() !== 0) {
            throw new \RuntimeException('MLB period materialization requires its own transaction and lock order.');
        }

        return $connection->transaction(function () use ($featureVersion, $games, $featuresByGame, $canonical, $sources, $source, $owners, $sourceIdentities, $eventIds, $predictionIds): Collection {
            if ($source->usesCanonical() !== $canonical) {
                return collect();
            }
            // Canonical publication owns Event before Prediction and may later request Game.
            // Lock only the event identities discovered before entering this transaction.
            $events = $canonical
                ? SportEvent::query()->whereIn('id', $eventIds)->orderBy('id')->lockForUpdate()->get(['id', 'sport', 'starts_at'])->keyBy('id')
                : collect();
            $currentGames = Game::query()->whereKey($games->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($games as $game) {
                $currentGame = $currentGames->get($game->id);
                $owner = $owners->get((int) $game->id);
                if ($currentGame === null || $currentGame->sport_event_id !== $owner['event_id']
                    || $currentGame->start_context_hash !== $owner['start_context_hash']
                    || MlbGameStart::for($currentGame)?->format('Y-m-d\TH:i:s.uP') !== $owner['start']
                    || ! in_array($currentGame->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
                    return collect();
                }
                if ($canonical) {
                    $event = $events->get($owner['event_id']);
                    if ($event === null || $event->sport !== 'mlb' || MlbGameStart::for($currentGame) === null
                        || $event->starts_at === null || ! $event->starts_at->eq(MlbGameStart::for($currentGame))) {
                        return collect();
                    }
                    $currentGame->setRelation('sportEvent', $event);
                }
            }
            if ($canonical) {
                $predictions = CanonicalPrediction::query()->whereIn('id', $predictionIds)->orderBy('id')->lockForUpdate()->get(['id', 'sport', 'sport_event_id'])->keyBy('id');
                foreach ($sources as $gameId => $snapshot) {
                    $prediction = $predictions->get($snapshot->id);
                    if ($prediction === null || $prediction->sport !== 'mlb'
                        || $prediction->sport_event_id !== $owners->get((int) $gameId)['event_id']) {
                        return collect();
                    }
                }
                $current = $source->forGames($currentGames)->map(fn ($snapshot): array => [
                    'id' => $snapshot->id, 'game_id' => $snapshot->game_id,
                    'feature_hash' => $snapshot->feature_hash,
                    'generated_at' => $snapshot->generated_at->format('Y-m-d\TH:i:s.uP'),
                    'features_available_at' => $snapshot->features_available_at->format('Y-m-d\TH:i:s.uP'),
                    'game_start_at' => $snapshot->game_start_at->format('Y-m-d\TH:i:s.uP'),
                ]);
                if ($current->all() !== $sourceIdentities->all()) {
                    return collect();
                }
            }

            return $games->map(function (Game $game) use ($featureVersion, $featuresByGame, $canonical, $sources, $currentGames): ?PeriodFeatureSnapshot {
                $predictionId = $canonical ? $sources->get($game->id)?->id : null;
                $game = $currentGames->get($game->id);
                if (MlbGameStart::for($game) === null) {
                    return null;
                }
                $features = $featuresByGame[(int) $game->id] ?? null;
                if (! is_array($features) || $features === []) {
                    return null;
                }

                $prior = PeriodFeatureSnapshot::query()->where('game_id', $game->id)->where('feature_version', $featureVersion)->where('forecast_source', $canonical ? 'canonical' : 'legacy')->where('canonical_prediction_id', $predictionId)->orderByDesc('id')->lockForUpdate()->first();
                if ($prior !== null && hash_equals((string) $prior->feature_hash, app(CanonicalPayloadHasher::class)->hash($prior->features))
                    && MlbGameStart::capturedFor($game, data_get($prior->features, '_start_observation'))
                    && app(CanonicalPayloadHasher::class)->hash(array_intersect_key($prior->features, MlbPeriodFeatureBuilder::MARKETS)) === app(CanonicalPayloadHasher::class)->hash($features)) {
                    return $prior;
                }
                $availableAt = CarbonImmutable::now('UTC');
                $legacyAvailableAt = MlbGameStart::legacyTimestamp($availableAt);
                if ($legacyAvailableAt === null) {
                    return null;
                }
                $features['_capture_id'] = (string) Str::uuid();
                $features['_start_observation'] = MlbGameStart::capture($game, $availableAt);
                $encoded = app(CanonicalPayloadHasher::class)->encode($features);
                $gameStart = MlbGameStart::for($game);
                $pregameSafe = $gameStart !== null && $availableAt->lessThan($gameStart) && MlbGameStart::observedBy($game, $availableAt);

                return PeriodFeatureSnapshot::query()->firstOrCreate(
                    [
                        'game_id' => (int) $game->id,
                        'feature_version' => $featureVersion,
                        'feature_hash' => hash('sha256', $encoded),
                    ],
                    [
                        'forecast_source' => $canonical ? 'canonical' : 'legacy',
                        'canonical_prediction_id' => $predictionId,
                        'features' => $features,
                        'game_start_at' => MlbGameStart::canonical($game),
                        'features_available_at' => $legacyAvailableAt,
                        'pregame_safe' => $pregameSafe,
                        'availability_status' => $pregameSafe
                            ? 'observed_pregame'
                            : 'reconstructed_now',
                    ],
                );
            })->filter()->values();
        });
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return Collection<int, PeriodFeatureSnapshot>
     */
    private function latestSnapshots(Collection $games, ?array $canonicalPredictionIds = null): Collection
    {
        if ($games->isEmpty()) {
            return collect();
        }

        $canonical = app(MlbPeriodForecastSource::class)->usesCanonical();
        if ($canonicalPredictionIds !== null && ! $canonical) {
            return collect();
        }
        if ($canonical) {
            (new ModelCollection($games->all()))->loadMissing('sportEvent:id,starts_at');
        }
        $sources = $canonical ? app(MlbPeriodForecastSource::class)->forGames($games) : collect();
        $games = $games->keyBy('id');

        return PeriodFeatureSnapshot::query()
            ->whereIn('game_id', $games->keys())
            ->where('forecast_source', $canonical ? 'canonical' : 'legacy')
            ->when(! $canonical, fn ($query) => $query->whereNull('canonical_prediction_id'))
            ->where('feature_version', $this->featureVersion())
            ->orderByDesc('id')
            ->get()
            ->filter(function (PeriodFeatureSnapshot $snapshot) use ($canonical, $sources, $games, $canonicalPredictionIds): bool {
                if (! $canonical) {
                    return true;
                }
                $id = $sources->get($snapshot->game_id)?->id;
                $game = $games->get($snapshot->game_id);
                $start = $game->sportEvent?->starts_at;
                $localStart = MlbGameStart::for($game);

                return $snapshot->canonical_prediction_id === $id
                    && ($canonicalPredictionIds === null || ($canonicalPredictionIds[$snapshot->game_id] ?? null) === $id)
                    && $start !== null && $snapshot->game_start_at?->eq($start) === true
                    && $localStart !== null && $start->eq($localStart);
            })
            ->unique(fn (PeriodFeatureSnapshot $snapshot): int => (int) $snapshot->game_id)
            ->filter(fn (PeriodFeatureSnapshot $snapshot): bool => $snapshot->pregame_safe && isset($games[$snapshot->game_id]) && hash_equals((string) $snapshot->feature_hash, app(CanonicalPayloadHasher::class)->hash($snapshot->features)) && MlbGameStart::capturedFor($games[$snapshot->game_id], data_get($snapshot->features, '_start_observation')))
            ->values();
    }

    private function featureVersion(): string
    {
        return (string) config(
            'mlb_ml.period_models.feature_snapshot_version',
            self::FEATURE_VERSION,
        );
    }

    /** @param  Collection<int, mixed>  $games */
    private function validGames(Collection $games): Collection
    {
        foreach ($games as $game) {
            if ($game instanceof Game && ((new Game)->getConnection()->getName() !== $game->getConnection()->getName()
                || (new PeriodFeatureSnapshot)->getConnection()->getName() !== $game->getConnection()->getName())) {
                throw new \RuntimeException('MLB period captures require one coherent database connection.');
            }
        }

        return $games
            ->filter(fn (mixed $game): bool => $game instanceof Game && is_numeric($game->id))
            ->unique('id')
            ->values();
    }
}
