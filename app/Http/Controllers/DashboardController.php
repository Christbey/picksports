<?php

namespace App\Http\Controllers;

use App\Http\Resources\CanonicalDashboardPredictionResource;
use App\Http\Resources\DashboardPredictionResource;
use App\Models\Healthcheck;
use App\Models\MLB\Prediction as MLBPrediction;
use App\Services\Api\V2\CanonicalPredictionPresentationService;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\DashboardSportConfig;
use App\Services\Api\V2\PredictionModelBetContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\BettingRecommendations\CanonicalBettingRecommendations;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Services\Predictions\LivePredictionStore;
use App\Services\Sports\SportsDateWindowService;
use App\Support\SportsViewCache;
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
        $dates = app(SportsDateWindowService::class);
        $date = $dates->parseLocalDate()->toDateString();
        $sportConfigs = app(DashboardSportConfig::class)->viewable($user);
        $cacheKey = $this->sportsViewCache->contextHash([
            'projection' => 'compact-v1',
            'date' => $date,
            'timezone' => $dates->timezone(),
            'selection_version' => 2,
            'viewable_sports' => array_keys($sportConfigs),
            'canonical_reads' => config('prediction_lifecycle.canonical_reads'),
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: 'dashboard',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.dashboard_seconds', 20),
            resolver: function () use ($date, $dates, $sportConfigs): array {
                $todaysPredictions = collect($sportConfigs)
                    ->flatMap(fn (array $config, string $sport) => $this->getPredictionsForSport($sport, $config, $date));
                $predictionsBySport = $todaysPredictions->groupBy('sport')
                    ->map(fn (Collection $predictions) => $predictions->sortBy('game_time')->values());

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

                $todayGameCount = fn (string $model) => $dates->applyGameDateWindow($model::query(), $dates->forDate($date))->count();

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

    private function getPredictionsForSport(string $sport, array $config, string $date): Collection
    {
        $context = app(SportContextResolver::class)->resolve(strtolower($sport));
        $selected = app(SportPredictionReader::class)->query($context, ['from_date' => $date, 'to_date' => $date], auth()->user());
        $canonical = app(CanonicalSportPredictionQuery::class);
        if ($canonical->supports($context)) {
            // The dashboard sorts by kickoff below. Sorting full JSON rows in MySQL
            // is expensive, so retain publication tie-breaking in the bounded slate.
            $rows = collect();
            $query = $selected
                ->reorder()
                ->withOnly([
                    'markets',
                    'sportEvent.'.$context->slug.'Game.homeTeam',
                    'sportEvent.'.$context->slug.'Game.awayTeam',
                    'latestEvaluation',
                    ...match ($context->slug) {
                        'cfb' => ['calculationRun:id,event_input_snapshot_id', 'calculationRun.inputSnapshot:id,inputs', 'sportEvent.cfbGame.weather', 'sportEvent.cfbGame.latestLiveSnapshot:cfb_live_prediction_snapshots.id,cfb_live_prediction_snapshots.game_id,status,projection,observed_at'],
                        'mlb' => ['calculationRun.release', 'calculationRun.inputSnapshot'],
                        default => [],
                    },
                ]);

            $batchSize = match ($context->slug) {
                'nba', 'cbb', 'wcbb' => 20,
                default => 5,
            };

            (clone $query)->select('predictions.id')->setEagerLoads([])
                ->chunkById($batchSize, function ($ids) use ($query, $context, $config, $rows): void {
                    $predictions = (clone $query)->whereKey($ids->modelKeys())->get();
                    app(LivePredictionStore::class)->preload($predictions->map(fn ($prediction) => $prediction->sportEvent?->getRelation($context->slug.'Game'))->filter());
                    $marketContexts = app(PredictionModelBetContext::class)->forDashboardBatch($context->slug, $predictions);
                    $signals = app(CanonicalBettingRecommendations::class)->dashboardSignals($context->slug, $predictions);
                    foreach ($predictions as $prediction) {
                        $gameId = $prediction->sportEvent?->getRelation($context->slug.'Game')?->getKey();
                        $rows->push([
                            'published_at' => $prediction->published_at,
                            'id' => $prediction->id,
                            'payload' => (new CanonicalDashboardPredictionResource(
                                $prediction, $context, $config, app(CanonicalPredictionPresentationService::class)->forDashboardPrediction($prediction, true), compact: true, marketContext: $marketContexts[$gameId] ?? [],
                                dashboardSignal: $signals[$prediction->getKey()] ?? null,
                            ))->resolve(),
                        ]);
                    }
                }, 'predictions.id', 'id');

            return $rows->sortBy([['published_at', 'desc'], ['id', 'desc']])->pluck('payload')->values();
        }

        $predictions = $selected->get();

        app(LivePredictionStore::class)->preload($predictions->pluck('game')->filter());

        return $predictions->map(function ($prediction) use ($sport, $config) {
            $resource = DashboardPredictionResource::make($prediction)
                ->liveFields(app(LivePredictionStore::class)->fields($prediction->game))
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
}
