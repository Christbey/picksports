<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\SportGameResource;
use App\Http\Resources\Api\V2\SportPredictionResource;
use App\Http\Resources\Api\V2\SportTeamMetricResource;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Api\V2\SportPredictionPresentationService;
use App\Services\Api\V2\SportTeamMetricQuery;
use App\Services\Sports\GameMatchupContextService;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportGamePageController extends Controller
{
    public function __invoke(
        string $sport,
        string $game,
        Request $request,
        SportContextResolver $sports,
        SportGameQuery $games,
        SportPredictionPresentationService $presentations,
        SportTeamMetricQuery $metrics,
        GameMatchupContextService $matchupContext,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        abort_unless($context->slug === 'mlb', 404, 'The composite game page is currently available for MLB.');

        $resolvedGame = $games->find($context, $game, $request->user(), 'page');
        $this->hydrateStartingPitchers($resolvedGame, $context->models['player']);
        $resolvedGame->setAttribute('matchup_context', $matchupContext->forGame($resolvedGame));
        $gameStart = MlbGameStart::for($resolvedGame)?->toIso8601String()
            ?? $resolvedGame->getAttribute('game_date')?->toDateString();
        $recentFilters = [
            'status' => 'STATUS_FINAL',
            'season' => (int) $resolvedGame->getAttribute('season'),
            'before_game_at' => $gameStart,
            'exclude_game_id' => (int) $resolvedGame->getKey(),
        ];

        $homeTeamId = (int) $resolvedGame->getAttribute('home_team_id');
        $awayTeamId = (int) $resolvedGame->getAttribute('away_team_id');
        $metricFilters = [
            'season' => (int) $resolvedGame->getAttribute('season'),
            'season_type' => (string) $resolvedGame->getAttribute('season_type'),
        ];

        $prediction = $this->prediction(
            $context,
            $resolvedGame,
            $request,
            $presentations,
        );
        $recentGames = $this->recentGames(
            $context,
            $games,
            $recentFilters,
            $homeTeamId,
            $awayTeamId,
            $request,
        );

        return response()->json([
            'data' => [
                'game' => (new SportGameResource($resolvedGame, $context))->resolve($request),
                'prediction' => $prediction,
                'recent_games' => $recentGames,
                'metrics' => [
                    'home' => $this->teamMetric($context, $metrics, $homeTeamId, $metricFilters, $request),
                    'away' => $this->teamMetric($context, $metrics, $awayTeamId, $metricFilters, $request),
                ],
                'depth_charts_available' => $this->depthChartsAvailable(
                    $context->models['depth_chart_entry'],
                    [$homeTeamId, $awayTeamId],
                    (int) $resolvedGame->getAttribute('season'),
                ),
            ],
            'meta' => [
                'version' => 'v2',
                'contract' => 'sports.games.page.show',
                'sport' => $context->slug,
                'game_id' => (int) $resolvedGame->getKey(),
                'deferred' => ['matchup_trends', 'depth_charts'],
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function prediction(
        SportContext $context,
        Model $game,
        Request $request,
        SportPredictionPresentationService $presentations,
    ): ?array {
        $prediction = $game->relationLoaded('prediction')
            ? $game->getRelation('prediction')
            : null;

        if (! $prediction instanceof Model) {
            return null;
        }

        $prediction->setRelation('game', $game);

        return (new SportPredictionResource(
            $prediction,
            $context,
            $presentations->forPrediction($context, $prediction),
        ))->resolve($request);
    }

    /** @return array{home: array<int, array<string, mixed>>, away: array<int, array<string, mixed>>} */
    private function recentGames(
        SportContext $context,
        SportGameQuery $games,
        array $filters,
        int $homeTeamId,
        int $awayTeamId,
        Request $request,
    ): array {
        $load = fn (int $teamId) => $games->query(
            $context,
            [...$filters, 'team_id' => $teamId],
            $request->user(),
            'identity',
        )->limit(5)->get();
        $homeGames = $load($homeTeamId);
        $awayGames = $load($awayTeamId);
        $allGames = $homeGames->concat($awayGames)->unique('id')->values();
        $teamModel = $context->models['team'];
        $teams = $teamModel::query()
            ->whereIn('id', $allGames->flatMap(fn (Model $game): array => [
                (int) $game->getAttribute('home_team_id'),
                (int) $game->getAttribute('away_team_id'),
            ])->unique())
            ->get()
            ->keyBy(fn (Model $team): int => (int) $team->getKey());

        $allGames->each(function (Model $game) use ($teams): void {
            $game->setRelation('homeTeam', $teams->get((int) $game->getAttribute('home_team_id')));
            $game->setRelation('awayTeam', $teams->get((int) $game->getAttribute('away_team_id')));
        });

        $serialize = fn ($recentGames): array => $recentGames
            ->map(fn (Model $game): array => (new SportGameResource($game, $context))->resolve($request))
            ->values()
            ->all();

        return [
            'home' => $serialize($homeGames),
            'away' => $serialize($awayGames),
        ];
    }

    /** @return array<string, mixed>|null */
    private function teamMetric(
        SportContext $context,
        SportTeamMetricQuery $metrics,
        int $teamId,
        array $filters,
        Request $request,
    ): ?array {
        try {
            $metric = $metrics->latestForTeam(
                $context,
                $teamId,
                $filters,
                $request->user(),
                includeTeam: false,
            );
        } catch (ModelNotFoundException) {
            return null;
        }

        return (new SportTeamMetricResource(
            $metric,
            $context,
            $metrics->preparedRecord($context, $metric),
        ))->resolve($request);
    }

    /**
     * @param  class-string<Model>  $playerModel
     */
    private function hydrateStartingPitchers(Model $game, string $playerModel): void
    {
        $gameRelations = [
            'probableHomePitcher' => 'probable_home_pitcher_espn_id',
            'probableAwayPitcher' => 'probable_away_pitcher_espn_id',
            'actualHomePitcher' => 'actual_home_pitcher_espn_id',
            'actualAwayPitcher' => 'actual_away_pitcher_espn_id',
            'projectedHomePitcher' => 'projected_home_pitcher_espn_id',
            'projectedAwayPitcher' => 'projected_away_pitcher_espn_id',
        ];
        $forecasts = collect([
            $game->getRelation('homeStartingPitcherForecast'),
            $game->getRelation('awayStartingPitcherForecast'),
        ])->filter(fn (mixed $forecast): bool => $forecast instanceof Model);
        $ids = collect($gameRelations)
            ->map(fn (string $attribute): string => trim((string) $game->getAttribute($attribute)))
            ->merge($forecasts->flatMap(fn (Model $forecast): array => [
                trim((string) $forecast->getAttribute('predicted_pitcher_espn_id')),
                trim((string) $forecast->getAttribute('actual_pitcher_espn_id')),
            ]))
            ->filter()
            ->unique()
            ->values();
        $players = $ids->isEmpty()
            ? collect()
            : $playerModel::query()
                ->whereIn('espn_id', $ids)
                ->get()
                ->keyBy(fn (Model $player): string => (string) $player->getAttribute('espn_id'));

        foreach ($gameRelations as $relation => $attribute) {
            $game->setRelation($relation, $players->get((string) $game->getAttribute($attribute)));
        }

        foreach ($forecasts as $forecast) {
            $forecast->setRelation(
                'predictedPitcher',
                $players->get((string) $forecast->getAttribute('predicted_pitcher_espn_id')),
            );
            $forecast->setRelation(
                'actualPitcher',
                $players->get((string) $forecast->getAttribute('actual_pitcher_espn_id')),
            );
        }
    }

    /**
     * @param  class-string<Model>  $depthChartEntryModel
     * @param  array<int, int>  $teamIds
     */
    private function depthChartsAvailable(string $depthChartEntryModel, array $teamIds, int $season): bool
    {
        return $depthChartEntryModel::query()
            ->whereIn('team_id', $teamIds)
            ->where('season', $season)
            ->where('is_starter', true)
            ->exists();
    }
}
