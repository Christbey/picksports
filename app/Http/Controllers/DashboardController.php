<?php

namespace App\Http\Controllers;

use App\Actions\CBB\CalculateBettingValue as CBBCalculateBettingValue;
use App\Actions\NBA\CalculateBettingValue as NBACalculateBettingValue;
use App\Actions\NFL\CalculateBettingValue as NFLCalculateBettingValue;
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
use App\Models\WCBB\Game as WCBBGame;
use App\Models\WCBB\Prediction as WCBBPrediction;
use App\Models\WNBA\Game as WNBAGame;
use App\Models\WNBA\Prediction as WNBAPrediction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const DEFAULT_LIVE_STATUSES = ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'];

    private const DEFAULT_FINAL_STATUSES = ['STATUS_FINAL', 'STATUS_FULL_TIME'];

    public function __invoke(): Response
    {
        $todayStartUtc = now()->startOfDay()->utc()->format('Y-m-d H:i:s');
        $todayEndUtc = now()->endOfDay()->utc()->format('Y-m-d H:i:s');

        $todayGameScope = fn (Builder $q) => $q->whereRaw(
            'TIMESTAMP(game_date, game_time) BETWEEN ? AND ?',
            [$todayStartUtc, $todayEndUtc]
        );

        $sportConfigs = $this->sportConfigs();

        $todaysPredictions = collect($sportConfigs)
            ->flatMap(fn (array $config, string $sport) => $this->getPredictionsForSport($sport, $config, $todayGameScope));

        $user = auth()->user();
        $predictionsPerDay = $user->subscriptionTier()?->features['predictions_per_day'] ?? null;

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

        $todayGameCount = fn (string $model) => $model::whereRaw(
            'TIMESTAMP(game_date, game_time) BETWEEN ? AND ?',
            [$todayStartUtc, $todayEndUtc]
        )->count();

        $stats = [
            'total_predictions_today' => $predictionsBySport->sum(fn (Collection $predictions) => $predictions->count()),
            'total_games_today' => collect($sportConfigs)
                ->sum(fn (array $config) => $todayGameCount($config['game_model'])),
            'healthcheck_status' => Healthcheck::where('checked_at', '>=', now()->subHours(1))
                ->where('status', 'failing')
                ->exists() ? 'failing' : 'passing',
        ];

        return Inertia::render('Dashboard', [
            'sports' => $sports,
            'stats' => $stats,
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
                gameModel: WCBBGame::class
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
                liveStatuses: ['STATUS_IN_PROGRESS', 'STATUS_DELAYED'],
                liveRemainingField: 'live_outs_remaining',
                includeInning: true
            ),
            'CFB' => $this->sportConfig(
                fullName: 'College Football',
                color: 'blue',
                predictionModel: CFBPrediction::class,
                gameModel: CFBGame::class
            ),
            'WNBA' => $this->sportConfig(
                fullName: "Women's National Basketball Association",
                color: 'purple',
                predictionModel: WNBAPrediction::class,
                gameModel: WNBAGame::class
            ),
        ];
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

        return $predictions->map(function ($prediction) use ($sport, $config) {
            $resource = DashboardPredictionResource::make($prediction)
                ->sport($sport)
                ->statuses($config['live_statuses'], $config['final_statuses'])
                ->includeInning($config['include_inning'])
                ->liveRemainingField($config['live_remaining_field']);

            if ($config['betting_calculator']) {
                $resource->bettingValue(app($config['betting_calculator'])->execute($prediction->game));
            }

            return $resource->resolve();
        });
    }
}
