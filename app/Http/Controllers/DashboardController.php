<?php

namespace App\Http\Controllers;

use App\Http\Resources\CanonicalDashboardPredictionResource;
use App\Http\Resources\DashboardPredictionResource;
use App\Models\CanonicalPrediction;
use App\Models\Healthcheck;
use App\Models\MLB\Prediction as MLBPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\DashboardSportConfig;
use App\Services\Api\V2\SportContextResolver;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Services\Predictions\LivePredictionStore;
use App\Support\SportsViewCache;
use App\Support\TierAccessBypass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly SportsViewCache $sportsViewCache,
        private readonly MlbPredictionRecommendationService $mlbRecommendations,
        private readonly GameBettingRecommendationService $bettingRecommendations,
    ) {}

    public function __invoke(): Response
    {
        $user = auth()->user();
        $predictionsPerDay = app(TierAccessBypass::class)->shouldBypassTierChecks($user)
            ? null
            : ($user->subscriptionTier()?->features['predictions_per_day'] ?? null);
        $sportConfigs = app(DashboardSportConfig::class)->viewable($user);
        $cacheKey = $this->sportsViewCache->contextHash([
            'date' => now()->toDateString(),
            'predictions_per_day' => $predictionsPerDay,
            'viewable_sports' => array_keys($sportConfigs),
            'canonical_reads' => config('prediction_lifecycle.canonical_reads'),
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: 'dashboard',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.dashboard_seconds', 20),
            resolver: function () use ($predictionsPerDay, $sportConfigs): array {
                $todayStartUtc = now()->startOfDay()->utc()->format('Y-m-d H:i:s');
                $todayEndUtc = now()->endOfDay()->utc()->format('Y-m-d H:i:s');

                $todayGameScope = fn (Builder $q) => $this->applyTodayGameWindow($q, $todayStartUtc, $todayEndUtc);

                $todaysPredictions = collect($sportConfigs)
                    ->flatMap(fn (array $config, string $sport) => $this->getPredictionsForSport($sport, $config, $todayGameScope));

                $predictionsBySport = $todaysPredictions
                    ->groupBy('sport')
                    ->map(function (Collection $predictions) use ($predictionsPerDay) {
                        $sorted = $predictions->sortBy('game_time');

                        return $predictionsPerDay !== null
                            ? $sorted->take($predictionsPerDay)->values()
                            : $sorted->values();
                    });

                $sports = collect($sportConfigs)
                    ->map(function (array $config, string $sport) use ($predictionsBySport) {
                        return [
                            'name' => $sport,
                            'fullName' => $config['full_name'],
                            'color' => $config['color'],
                            'predictions' => $predictionsBySport->get($sport, collect())->values(),
                        ];
                    })
                    ->filter(fn (array $sport) => $sport['predictions']->isNotEmpty())
                    ->values();

                $todayGameCount = fn (string $model) => $this->applyTodayGameWindow($model::query(), $todayStartUtc, $todayEndUtc)->count();

                $stats = [
                    'total_predictions_today' => $predictionsBySport->sum(fn (Collection $predictions) => $predictions->count()),
                    'total_games_today' => collect($sportConfigs)
                        ->sum(fn (array $config) => $todayGameCount($config['game_model'])),
                    'healthcheck_status' => Healthcheck::where('checked_at', '>=', now()->subHours(1))
                        ->where('status', 'failing')
                        ->exists() ? 'failing' : 'passing',
                ];

                return [
                    'sports' => $sports->values()->all(),
                    'stats' => $stats,
                ];
            },
        );

        return Inertia::render('Dashboard', [
            'sports' => $payload['sports'],
            'stats' => $payload['stats'],
        ]);
    }

    private function getPredictionsForSport(string $sport, array $config, \Closure $todayGameScope): Collection
    {
        $context = app(SportContextResolver::class)->resolve(strtolower($sport));
        $canonical = app(CanonicalSportPredictionQuery::class);
        if ($canonical->supports($context)) {
            $predictions = $canonical->queryForSport($context->slug)
                ->whereBetween('sport_events.starts_at', [now()->startOfDay()->utc(), now()->endOfDay()->utc()])->get();
            app(LivePredictionStore::class)->preload($predictions->map(fn ($prediction) => $prediction->sportEvent?->getRelation($context->slug.'Game'))->filter());

            return $predictions->map(fn (CanonicalPrediction $prediction): array => (new CanonicalDashboardPredictionResource($prediction, $context, $config))->resolve());
        }

        $predictions = $config['prediction_model']::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', $todayGameScope)
            ->get();

        if (strtolower($sport) === 'cbb') {
            $predictions = $predictions->filter(function ($prediction) {
                $game = $prediction->game;

                return $game && ! $this->isPlaceholderCbbGame($game);
            })->values();
        }

        app(LivePredictionStore::class)->preload($predictions->pluck('game')->filter());

        return $predictions->map(function ($prediction) use ($sport, $config) {
            $resource = DashboardPredictionResource::make($prediction)
                ->sport($sport)
                ->statuses($config['live_statuses'], $config['final_statuses'])
                ->includeInning($config['include_inning'])
                ->liveRemainingField($config['live_remaining_field']);

            $analysis = $this->analyzeBettingValue($prediction->game, strtolower($sport));
            $resource
                ->bettingValue($analysis['recommendations'])
                ->bettingValueDebug($analysis['debug']);

            if (strtolower($sport) === 'mlb' && $prediction instanceof MLBPrediction) {
                $resource->recommendation($this->mlbRecommendations->forPrediction($prediction));
            }

            return $resource->resolve();
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

    /**
     * @return array{recommendations:array<int,mixed>|null,debug:string|null}
     */
    private function analyzeBettingValue(object $game, string $sportKey): array
    {
        $oddsData = $game->odds_data ?? null;
        $bookmakers = is_array($oddsData) ? ($oddsData['bookmakers'] ?? null) : null;
        $hasBookmakers = is_array($bookmakers) && $bookmakers !== [];

        if (! $hasBookmakers) {
            return [
                'recommendations' => null,
                'debug' => 'No odds',
            ];
        }

        $hasMarketData = collect($bookmakers)
            ->flatMap(fn ($bookmaker) => is_array($bookmaker) ? ($bookmaker['markets'] ?? []) : [])
            ->contains(fn ($market) => in_array(($market['key'] ?? null), ['spreads', 'totals', 'h2h'], true));

        if (! $hasMarketData) {
            return [
                'recommendations' => null,
                'debug' => 'No odds',
            ];
        }

        $recommendations = $this->bettingRecommendations->forGame($game, $sportKey);

        if (is_array($recommendations) && $recommendations !== []) {
            return [
                'recommendations' => $recommendations,
                'debug' => null,
            ];
        }

        return [
            'recommendations' => null,
            'debug' => 'Below threshold',
        ];
    }

    private function isPlaceholderCbbGame(object $game): bool
    {
        return $this->isPlaceholderCbbTeam($game->homeTeam ?? null)
            || $this->isPlaceholderCbbTeam($game->awayTeam ?? null)
            || str_starts_with((string) ($game->espn_event_id ?? ''), 'placeholder:');
    }

    private function isPlaceholderCbbTeam(?Model $team): bool
    {
        if (! $team) {
            return true;
        }

        $school = strtoupper(trim((string) ($team->school ?? '')));
        $abbreviation = strtoupper(trim((string) ($team->abbreviation ?? '')));
        $espnId = (int) ($team->espn_id ?? 0);

        return in_array($school, ['TBD', 'TBD2'], true)
            || in_array($abbreviation, ['TBD', 'TBD2', 'WFF', 'FF'], true)
            || $espnId < 0;
    }
}
