<?php

namespace App\Services\Api\V2;

use App\Http\Resources\Api\V2\SportGameResource;
use App\Http\Resources\Api\V2\SportTeamMetricResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class MlbGamePageData
{
    /** @return array{home: array<int, array<string, mixed>>, away: array<int, array<string, mixed>>} */
    public function recentGames(
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
        // The same matchup can appear as distinct model instances in both lists.
        $allGames = $homeGames->concat($awayGames)->values();
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
    public function teamMetric(
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
    public function hydrateStartingPitchers(Model $game, string $playerModel): void
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
    public function depthChartsAvailable(string $depthChartEntryModel, array $teamIds, int $season): bool
    {
        return $depthChartEntryModel::query()
            ->whereIn('team_id', $teamIds)
            ->where('season', $season)
            ->where('is_starter', true)
            ->exists();
    }
}
