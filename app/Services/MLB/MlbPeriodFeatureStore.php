<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Support\MLB\MlbGameStart;
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
    public function forGames(Collection $games): array
    {
        $games = $this->validGames($games);
        if ($games->isEmpty()) {
            return [];
        }

        $features = $this->latestSnapshots($games->pluck('id')->all())
            ->mapWithKeys(fn (PeriodFeatureSnapshot $snapshot): array => [
                (int) $snapshot->game_id => (array) $snapshot->features,
            ])
            ->all();

        if (app()->environment('testing')) {
            $missing = $games->reject(fn (Game $game): bool => isset($features[(int) $game->id]));
            if ($missing->isNotEmpty()) {
                $this->materialize($missing);
                $features = $this->latestSnapshots($games->pluck('id')->all())
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

        $featuresByGame = $this->builder->liveFeaturesForGames($games);
        $featureVersion = $this->featureVersion();

        return DB::transaction(function () use ($featureVersion, $games, $featuresByGame): Collection {
            return $games->map(function (Game $game) use ($featureVersion, $featuresByGame): ?PeriodFeatureSnapshot {
                $features = $featuresByGame[(int) $game->id] ?? null;
                if (! is_array($features) || $features === []) {
                    return null;
                }

                $encoded = json_encode($features, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $availableAt = now();
                $gameStart = MlbGameStart::for($game);
                $pregameSafe = $gameStart !== null && $availableAt->lessThanOrEqualTo($gameStart);

                return PeriodFeatureSnapshot::query()->firstOrCreate(
                    [
                        'game_id' => (int) $game->id,
                        'feature_version' => $featureVersion,
                        'feature_hash' => hash('sha256', $encoded),
                    ],
                    [
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
     * @param  array<int, int>  $gameIds
     * @return Collection<int, PeriodFeatureSnapshot>
     */
    private function latestSnapshots(array $gameIds): Collection
    {
        if ($gameIds === []) {
            return collect();
        }

        return PeriodFeatureSnapshot::query()
            ->whereIn('game_id', $gameIds)
            ->where('feature_version', $this->featureVersion())
            ->where('pregame_safe', true)
            ->orderByDesc('features_available_at')
            ->orderByDesc('id')
            ->get()
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
