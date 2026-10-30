<?php

namespace App\Services\MLB;

use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\SportEvent;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MlbPeriodFeatureStore
{
    public const FEATURE_VERSION = 'mlb-period-live-v1';

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
                (int) $snapshot->game_id => (array) $snapshot->features,
            ])
            ->all();

        if (app()->environment('testing')) {
            $missing = $games->reject(fn (Game $game): bool => isset($features[(int) $game->id]));
            if ($missing->isNotEmpty()) {
                $this->materialize($missing);
                $features = $this->latestSnapshots($games, $canonicalPredictionIds)
                    ->mapWithKeys(fn (PeriodFeatureSnapshot $snapshot): array => [
                        (int) $snapshot->game_id => (array) $snapshot->features,
                    ])
                    ->all();
            }
        }

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

        $source = app(MlbPeriodForecastSource::class);
        $canonical = $source->usesCanonical();
        if ($canonical) {
            (new ModelCollection($games->all()))->loadMissing('sportEvent:id,starts_at');
        }
        $sources = $canonical ? $source->forGames($games) : collect();
        $featuresByGame = $this->builder->liveFeaturesForGames($games, $canonical ? $sources : null);
        $featureVersion = $this->featureVersion();

        return DB::transaction(function () use ($featureVersion, $games, $featuresByGame, $canonical, $sources, $source): Collection {
            if ($source->usesCanonical() !== $canonical) {
                return collect();
            }
            if ($canonical) {
                SportEvent::query()->whereIn('id', $games->pluck('sport_event_id')->filter())->orderBy('id')->lockForUpdate()->get(['id']);
                CanonicalPrediction::query()->whereIn('id', $sources->pluck('id'))->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $current = $canonical ? $source->forGames($games) : collect();

            return $games->map(function (Game $game) use ($featureVersion, $featuresByGame, $canonical, $sources, $current): ?PeriodFeatureSnapshot {
                $predictionId = $canonical ? $sources->get($game->id)?->id : null;
                if ($canonical && $predictionId !== $current->get($game->id)?->id) {
                    return null;
                }
                $features = $featuresByGame[(int) $game->id] ?? null;
                if (! is_array($features) || $features === []) {
                    return null;
                }

                $encoded = json_encode($canonical ? ['forecast_source' => 'canonical', 'canonical_prediction_id' => $predictionId, 'features' => $features] : $features, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $availableAt = now();
                $gameStart = $canonical ? $game->sportEvent?->starts_at : MlbGameStart::for($game);
                $localStart = MlbGameStart::for($game);
                $pregameSafe = $gameStart !== null && $availableAt->lessThanOrEqualTo($gameStart)
                    && (! $canonical || ($localStart !== null && $gameStart->eq($localStart)));

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
                        'game_start_at' => $gameStart,
                        'features_available_at' => $availableAt,
                        'pregame_safe' => $pregameSafe,
                        'availability_status' => $pregameSafe
                            ? 'observed_pregame'
                            : 'verified_reconstruction',
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
            ->where('pregame_safe', true)
            ->orderByDesc('features_available_at')
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
        return $games
            ->filter(fn (mixed $game): bool => $game instanceof Game && is_numeric($game->id))
            ->unique('id')
            ->values();
    }
}
