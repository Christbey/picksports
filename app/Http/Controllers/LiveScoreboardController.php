<?php

namespace App\Http\Controllers;

use App\Http\Resources\DashboardPredictionResource;
use App\Models\CBB\Prediction as CBBPrediction;
use App\Models\CFB\Prediction as CFBPrediction;
use App\Models\MLB\Prediction as MLBPrediction;
use App\Models\NBA\Prediction as NBAPrediction;
use App\Models\NFL\Prediction as NFLPrediction;
use App\Models\WCBB\Prediction as WCBBPrediction;
use App\Models\WNBA\Prediction as WNBAPrediction;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class LiveScoreboardController extends Controller
{
    private const DEFAULT_LIVE_STATUSES = ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'];

    private const DEFAULT_FINAL_STATUSES = ['STATUS_FINAL', 'STATUS_FULL_TIME'];

    public function __construct(private readonly SportsViewCache $sportsViewCache) {}

    public function __invoke(): JsonResponse
    {
        $cacheKey = $this->sportsViewCache->contextHash([
            'date' => now()->toDateString(),
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: 'live_scoreboard',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.live_scoreboard_seconds', 10),
            resolver: function (): array {
                $todayStartUtc = now()->startOfDay()->utc()->format('Y-m-d H:i:s');
                $todayEndUtc = now()->endOfDay()->utc()->format('Y-m-d H:i:s');

                $todayGameScope = fn (Builder $q) => $this->applyTodayGameWindow($q, $todayStartUtc, $todayEndUtc);

                $games = collect($this->sportConfigs())
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

        return response()->json($payload);
    }

    private function sportConfigs(): array
    {
        return [
            'NBA' => $this->sportConfig(predictionModel: NBAPrediction::class),
            'CBB' => $this->sportConfig(predictionModel: CBBPrediction::class),
            'WCBB' => $this->sportConfig(predictionModel: WCBBPrediction::class),
            'NFL' => $this->sportConfig(predictionModel: NFLPrediction::class),
            'MLB' => $this->sportConfig(
                predictionModel: MLBPrediction::class,
                liveStatuses: ['STATUS_IN_PROGRESS', 'STATUS_DELAYED'],
                liveRemainingField: 'live_outs_remaining',
                includeInning: true,
            ),
            'CFB' => $this->sportConfig(predictionModel: CFBPrediction::class),
            'WNBA' => $this->sportConfig(predictionModel: WNBAPrediction::class),
        ];
    }

    /**
     * @return array{
     *   prediction_model:class-string,
     *   live_statuses:array<int,string>,
     *   final_statuses:array<int,string>,
     *   live_remaining_field:string,
     *   include_inning:bool
     * }
     */
    private function sportConfig(
        string $predictionModel,
        ?array $liveStatuses = null,
        ?array $finalStatuses = null,
        string $liveRemainingField = 'live_seconds_remaining',
        bool $includeInning = false,
    ): array {
        return [
            'prediction_model' => $predictionModel,
            'live_statuses' => $liveStatuses ?? self::DEFAULT_LIVE_STATUSES,
            'final_statuses' => $finalStatuses ?? self::DEFAULT_FINAL_STATUSES,
            'live_remaining_field' => $liveRemainingField,
            'include_inning' => $includeInning,
        ];
    }

    private function getScoreboardGamesForSport(string $sport, array $config, \Closure $todayGameScope): Collection
    {
        $statuses = array_values(array_unique(array_merge(
            $config['live_statuses'],
            $config['final_statuses'],
        )));

        $predictions = $config['prediction_model']::with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function (Builder $query) use ($todayGameScope, $statuses): void {
                $todayGameScope($query);
                $query->whereIn('status', $statuses);
            })
            ->get();

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
     * @param  Builder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>|QueryBuilder
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
