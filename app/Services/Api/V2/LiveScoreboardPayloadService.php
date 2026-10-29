<?php

namespace App\Services\Api\V2;

use App\Http\Resources\CanonicalDashboardPredictionResource;
use App\Http\Resources\DashboardPredictionResource;
use App\Models\User;
use App\Services\Predictions\LivePredictionStore;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class LiveScoreboardPayloadService
{
    public function __construct(private readonly SportsViewCache $sportsViewCache) {}

    /**
     * @return array{games: array<int, array<string, mixed>>, updated_at: string}
     */
    public function payload(?User $user): array
    {
        $sportConfigs = app(DashboardSportConfig::class)->viewable($user);
        $cacheKey = $this->sportsViewCache->contextHash([
            'date' => now()->toDateString(),
            'viewable_sports' => array_keys($sportConfigs),
            'canonical_reads' => config('prediction_lifecycle.canonical_reads'),
        ]);

        return $this->sportsViewCache->remember(
            segment: 'live_scoreboard',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.live_scoreboard_seconds', 10),
            resolver: function () use ($sportConfigs): array {
                $todayStartUtc = now()->startOfDay()->utc()->format('Y-m-d H:i:s');
                $todayEndUtc = now()->endOfDay()->utc()->format('Y-m-d H:i:s');

                $todayGameScope = fn (Builder $q) => $this->applyTodayGameWindow($q, $todayStartUtc, $todayEndUtc);

                $games = collect($sportConfigs)
                    ->flatMap(fn (array $config, string $sport) => $this->getScoreboardGamesForSport($sport, $config, $todayGameScope))
                    ->sortBy([
                        fn (array $game) => $game['is_live'] ? 0 : 1,
                        ['game_time', 'asc'],
                    ])
                    ->take(24)
                    ->values();

                return [
                    'games' => $games->all(),
                    'updated_at' => now()->toIso8601String(),
                ];
            },
        );
    }

    private function getScoreboardGamesForSport(string $sport, array $config, \Closure $todayGameScope): Collection
    {
        $statuses = array_values(array_unique(array_merge(
            $config['live_statuses'],
            $config['final_statuses'],
        )));

        $context = app(SportContextResolver::class)->resolve(strtolower($sport));
        $canonical = app(CanonicalSportPredictionQuery::class);
        if ($canonical->supports($context)) {
            $predictions = $canonical->queryForSport($context->slug, ['from_date' => now()->subDay()->toDateString()])
                ->whereBetween('sport_events.starts_at', [now()->startOfDay()->utc(), now()->endOfDay()->utc()])
                ->whereIn($context->slug.'_games.status', $statuses)->get();
            app(LivePredictionStore::class)->preload($predictions->map(fn ($prediction) => $prediction->sportEvent?->getRelation($context->slug.'Game'))->filter());

            return $predictions->map(fn ($prediction): array => (new CanonicalDashboardPredictionResource($prediction, $context, $config))->resolve());
        }

        $predictions = $config['prediction_model']::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function (Builder $query) use ($todayGameScope, $statuses): void {
                $todayGameScope($query);
                $query->whereIn('status', $statuses);
            })
            ->get();

        app(LivePredictionStore::class)->preload($predictions->pluck('game')->filter());

        return $predictions->map(function ($prediction) use ($sport, $config) {
            return DashboardPredictionResource::make($prediction)
                ->sport($sport)
                ->statuses($config['live_statuses'], $config['final_statuses'])
                ->includeInning($config['include_inning'])
                ->liveRemainingField($config['live_remaining_field'])
                ->resolve();
        });
    }

    /**
     * @param  Builder<Model>|QueryBuilder  $query
     * @return Builder<Model>|QueryBuilder
     */
    private function applyTodayGameWindow(Builder|QueryBuilder $query, string $startUtc, string $endUtc): Builder|QueryBuilder
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return $query->whereRaw(
                "datetime(game_date || ' ' || COALESCE(game_time, '00:00:00')) BETWEEN ? AND ?",
                [$startUtc, $endUtc]
            );
        }

        return $query->whereRaw(
            'TIMESTAMP(game_date, game_time) BETWEEN ? AND ?',
            [$startUtc, $endUtc]
        );
    }
}
