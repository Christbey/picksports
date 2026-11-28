<?php

namespace App\Services\Api\V2;

use App\Http\Resources\CanonicalDashboardPredictionResource;
use App\Http\Resources\DashboardPredictionResource;
use App\Models\NFL\Game as NflGame;
use App\Models\User;
use App\Services\Predictions\LivePredictionStore;
use App\Services\Sports\DateWindow;
use App\Services\Sports\SportsDateWindowService;
use App\Support\NFL\NflGameStart;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class LiveScoreboardPayloadService
{
    private const DEFAULT_LIVE_STATUSES = ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'];

    private const MAX_MISSING_CLOCK_METADATA_BYTES = 65_536;

    private const DEFAULT_FINAL_STATUSES = ['STATUS_FINAL', 'STATUS_FULL_TIME'];

    public function __construct(
        private readonly SportsViewCache $sportsViewCache,
        private readonly SportsDateWindowService $dateWindows,
    ) {}

    /**
     * @return array{games: array<int, array<string, mixed>>, updated_at: string}
     */
    public function payload(?User $user): array
    {
        $sportConfigs = app(DashboardSportConfig::class)->viewable($user);
        $window = $this->dateWindows->forDate();
        $cacheKey = $this->sportsViewCache->contextHash([
            'date' => $window->localStartDate(),
            'timezone' => $window->timezone,
            'window_policy' => 'verified_start_or_unverified_calendar_v2',
            'viewable_sports' => array_keys($sportConfigs),
            'canonical_reads' => config('prediction_lifecycle.canonical_reads'),
        ]);

        return $this->sportsViewCache->remember(
            segment: 'live_scoreboard',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.live_scoreboard_seconds', 10),
            resolver: function () use ($sportConfigs, $window): array {
                $todayGameScope = function (Builder $query) use ($window): Builder {
                    return $query->where(function (Builder $candidates) use ($window): void {
                        $this->dateWindows->applyGameDateWindow($candidates, $window);
                        if ($candidates->getModel() instanceof NflGame) {
                            // Only bounded possible legacy carriers are inspected by the exact existing classifier below.
                            $candidates->orWhere(fn (Builder $legacy): Builder => $legacy
                                ->whereDate('game_date', $window->localStartDate())
                                ->whereNotNull('nflverse_game_id')
                                ->where('game_time', 'like', '00:00:00%')
                                ->whereRaw($legacy->getConnection()->getDriverName() === 'sqlite' ? 'LENGTH(CAST(odds_data AS BLOB)) <= ?' : 'LENGTH(odds_data) <= ?', [self::MAX_MISSING_CLOCK_METADATA_BYTES]));
                        }
                    });
                };

                $games = collect($sportConfigs)
                    ->flatMap(fn (array $config, string $sport) => $this->getScoreboardGamesForSport($sport, $config, $todayGameScope, $window))
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

    private function getScoreboardGamesForSport(string $sport, array $config, \Closure $todayGameScope, DateWindow $window): Collection
    {
        $statuses = array_values(array_unique(array_merge(
            $config['live_statuses'],
            $config['final_statuses'],
        )));

        $context = app(SportContextResolver::class)->resolve(strtolower($sport));
        $canonical = app(CanonicalSportPredictionQuery::class);
        if ($canonical->supports($context)) {
            $gameModel = $context->models['game'];
            $todayGames = $todayGameScope($gameModel::query());
            $gameTable = $todayGames->getModel()->getTable();
            $predictions = $canonical->queryForSport($context->slug)
                ->whereIn($gameTable.'.id', $todayGames->select($gameTable.'.id'))
                ->whereIn($gameTable.'.status', $statuses)->get()
                ->filter(fn ($prediction): bool => $this->matchesNflWindow($prediction->sportEvent->getRelation($context->slug.'Game'), $window));
            app(LivePredictionStore::class)->preload($predictions->map(fn ($prediction) => $prediction->sportEvent?->getRelation($context->slug.'Game'))->filter());

            return $predictions->map(fn ($prediction): array => (new CanonicalDashboardPredictionResource($prediction, $context, $config, app(CanonicalPredictionPresentationService::class)->forPrediction($prediction)))->resolve());
        }

        $predictions = $config['prediction_model']::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function (Builder $query) use ($todayGameScope, $statuses): void {
                $todayGameScope($query);
                $query->whereIn('status', $statuses);
            })
            ->get()
            ->filter(fn ($prediction): bool => $this->matchesNflWindow($prediction->game, $window));

        app(LivePredictionStore::class)->preload($predictions->pluck('game')->filter());

        return $predictions->map(function ($prediction) use ($sport, $config) {
            return DashboardPredictionResource::make($prediction)
                ->liveFields(app(LivePredictionStore::class)->fields($prediction->game))
                ->sport($sport)
                ->statuses($config['live_statuses'], $config['final_statuses'])
                ->includeInning($config['include_inning'])
                ->liveRemainingField($config['live_remaining_field'])
                ->resolve();
        });
    }

    private function matchesNflWindow(Model $game, DateWindow $window): bool
    {
        if (! $game instanceof NflGame) {
            return true;
        }
        $start = NflGameStart::for($game);

        return $start !== null
            ? $start->betweenIncluded($window->utcStart, $window->utcEnd)
            : substr((string) $game->getRawOriginal('game_date'), 0, 10) === $window->localStartDate();
    }
}
