<?php

namespace App\Http\Controllers;

use App\Actions\CBB\CalculateBettingValue as CBBCalculateBettingValue;
use App\Actions\NBA\CalculateBettingValue as NBACalculateBettingValue;
use App\Actions\NFL\CalculateBettingValue as NFLCalculateBettingValue;
use App\Actions\Sports\CalculateBettingValue as GenericCalculateBettingValue;
use App\Http\Resources\DashboardPredictionResource;
use App\Models\CBB\Game as CBBGame;
use App\Models\CBB\Prediction as CBBPrediction;
use App\Models\CFB\Game as CFBGame;
use App\Models\CFB\Prediction as CFBPrediction;
use App\Models\Healthcheck;
use App\Models\MLB\Game as MLBGame;
use App\Models\MLB\Prediction as MLBPrediction;
use App\Models\NBA\Game as NBAGame;
use App\Models\NBA\Prediction as NBAPrediction;
use App\Models\NFL\Game as NFLGame;
use App\Models\NFL\Prediction as NFLPrediction;
use App\Models\User;
use App\Models\WCBB\Game as WCBBGame;
use App\Models\WCBB\Prediction as WCBBPrediction;
use App\Models\WNBA\Game as WNBAGame;
use App\Models\WNBA\Prediction as WNBAPrediction;
use App\Support\SportPredictionAccess;
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
    private const DEFAULT_LIVE_STATUSES = ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'];

    private const DEFAULT_FINAL_STATUSES = ['STATUS_FINAL', 'STATUS_FULL_TIME'];

    public function __construct(private readonly SportsViewCache $sportsViewCache) {}

    public function __invoke(): Response
    {
        $user = auth()->user();
        $predictionsPerDay = app(TierAccessBypass::class)->shouldBypassTierChecks($user)
            ? null
            : ($user->subscriptionTier()?->features['predictions_per_day'] ?? null);
        $sportConfigs = $this->viewableSportConfigs($user);
        $cacheKey = $this->sportsViewCache->contextHash([
            'date' => now()->toDateString(),
            'predictions_per_day' => $predictionsPerDay,
            'viewable_sports' => array_keys($sportConfigs),
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

    private function sportConfigs(): array
    {
        return [
            'NBA' => $this->sportConfig(
                fullName: 'National Basketball Association',
                color: 'orange',
                predictionModel: NBAPrediction::class,
                gameModel: NBAGame::class,
                bettingCalculator: NBACalculateBettingValue::class
            ),
            'CBB' => $this->sportConfig(
                fullName: "Men's College Basketball",
                color: 'blue',
                predictionModel: CBBPrediction::class,
                gameModel: CBBGame::class,
                bettingCalculator: CBBCalculateBettingValue::class
            ),
            'WCBB' => $this->sportConfig(
                fullName: "Women's College Basketball",
                color: 'purple',
                predictionModel: WCBBPrediction::class,
                gameModel: WCBBGame::class,
                bettingCalculator: GenericCalculateBettingValue::class
            ),
            'NFL' => $this->sportConfig(
                fullName: 'National Football League',
                color: 'green',
                predictionModel: NFLPrediction::class,
                gameModel: NFLGame::class,
                bettingCalculator: NFLCalculateBettingValue::class
            ),
            'MLB' => $this->sportConfig(
                fullName: 'Major League Baseball',
                color: 'orange',
                predictionModel: MLBPrediction::class,
                gameModel: MLBGame::class,
                bettingCalculator: GenericCalculateBettingValue::class,
                liveStatuses: ['STATUS_IN_PROGRESS', 'STATUS_DELAYED'],
                liveRemainingField: 'live_outs_remaining',
                includeInning: true
            ),
            'CFB' => $this->sportConfig(
                fullName: 'College Football',
                color: 'blue',
                predictionModel: CFBPrediction::class,
                gameModel: CFBGame::class,
                bettingCalculator: GenericCalculateBettingValue::class
            ),
            'WNBA' => $this->sportConfig(
                fullName: "Women's National Basketball Association",
                color: 'purple',
                predictionModel: WNBAPrediction::class,
                gameModel: WNBAGame::class,
                bettingCalculator: GenericCalculateBettingValue::class
            ),
        ];
    }

    private function viewableSportConfigs(?User $user): array
    {
        return collect($this->sportConfigs())
            ->filter(fn (array $config, string $sport): bool => $this->canViewSportPredictions($user, $sport))
            ->all();
    }

    private function canViewSportPredictions(?User $user, string $sport): bool
    {
        return app(SportPredictionAccess::class)->canView($user, $sport);
    }

    /**
     * @return array{
     *   full_name:string,
     *   color:string,
     *   prediction_model:class-string,
     *   game_model:class-string,
     *   live_statuses:array<int,string>,
     *   final_statuses:array<int,string>,
     *   live_remaining_field:string,
     *   include_inning:bool,
     *   betting_calculator:class-string|null
     * }
     */
    private function sportConfig(
        string $fullName,
        string $color,
        string $predictionModel,
        string $gameModel,
        ?string $bettingCalculator = null,
        ?array $liveStatuses = null,
        ?array $finalStatuses = null,
        string $liveRemainingField = 'live_seconds_remaining',
        bool $includeInning = false,
    ): array {
        return [
            'full_name' => $fullName,
            'color' => $color,
            'prediction_model' => $predictionModel,
            'game_model' => $gameModel,
            'live_statuses' => $liveStatuses ?? self::DEFAULT_LIVE_STATUSES,
            'final_statuses' => $finalStatuses ?? self::DEFAULT_FINAL_STATUSES,
            'live_remaining_field' => $liveRemainingField,
            'include_inning' => $includeInning,
            'betting_calculator' => $bettingCalculator,
        ];
    }

    private function getPredictionsForSport(string $sport, array $config, \Closure $todayGameScope): Collection
    {
        $predictions = $config['prediction_model']::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', $todayGameScope)
            ->get();

        if (strtolower($sport) === 'cbb') {
            $predictions = $predictions->filter(function ($prediction) {
                $game = $prediction->game;

                return $game && ! $this->isPlaceholderCbbGame($game);
            })->values();
        }

        return $predictions->map(function ($prediction) use ($sport, $config) {
            $resource = DashboardPredictionResource::make($prediction)
                ->sport($sport)
                ->statuses($config['live_statuses'], $config['final_statuses'])
                ->includeInning($config['include_inning'])
                ->liveRemainingField($config['live_remaining_field']);

            if ($config['betting_calculator']) {
                $analysis = $this->analyzeBettingValue($prediction->game, $config['betting_calculator'], strtolower($sport));
                $resource
                    ->bettingValue($analysis['recommendations'])
                    ->bettingValueDebug($analysis['debug']);
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
    private function analyzeBettingValue(object $game, string $calculatorClass, string $sportKey): array
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

        $calculator = app($calculatorClass);
        $recommendations = $calculatorClass === GenericCalculateBettingValue::class
            ? $calculator->execute($game, $sportKey)
            : $calculator->execute($game);

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
