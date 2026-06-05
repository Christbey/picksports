<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SportTeamStatAverageController extends Controller
{
    private const SUPPORTED_SPORTS = ['nba', 'cbb', 'wcbb', 'wnba', 'mlb'];

    public function index(
        string $sport,
        SportContextResolver $sports,
        BasketballTeamSeasonAveragesService $basketballAverages,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $this->ensureSupported($context);

        return response()->json([
            'data' => $this->allAverages($context, $basketballAverages),
            'meta' => $this->meta($context, 'sports.stats.team.season-averages.index'),
        ]);
    }

    public function teamShow(
        string $sport,
        string $team,
        SportContextResolver $sports,
        BasketballTeamSeasonAveragesService $basketballAverages,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $this->ensureSupported($context);
        $teamId = (int) $team;
        $payload = $this->teamAverages($context, $basketballAverages, $teamId);

        if (! $payload) {
            return response()->json([
                'message' => 'Team stat season averages are not available.',
                'meta' => $this->meta($context, 'sports.teams.stats.season-averages.show') + [
                    'team_id' => $teamId,
                ],
            ], 404);
        }

        return response()->json([
            'data' => $payload,
            'meta' => $this->meta($context, 'sports.teams.stats.season-averages.show') + [
                'team_id' => $teamId,
            ],
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function allAverages(SportContext $context, BasketballTeamSeasonAveragesService $basketballAverages): array
    {
        $teamStatModel = $this->teamStatModel($context);

        if ($context->slug === 'mlb') {
            return $this->mlbAllAverages($teamStatModel);
        }

        return $basketballAverages->allTeams($teamStatModel)->values()->all();
    }

    /**
     * @return array<string, mixed>|object|null
     */
    private function teamAverages(
        SportContext $context,
        BasketballTeamSeasonAveragesService $basketballAverages,
        int $teamId,
    ): array|object|null {
        $teamStatModel = $this->teamStatModel($context);

        if ($context->slug === 'mlb') {
            return $this->mlbTeamAverages($teamId);
        }

        return $basketballAverages->forTeam(
            $teamStatModel,
            $teamId,
            includeTeamId: true,
            includeFouls: $context->slug === 'nba',
        );
    }

    /**
     * @param  class-string<Model>  $teamStatModel
     * @return array<int, object>
     */
    private function mlbAllAverages(string $teamStatModel): array
    {
        $table = (new $teamStatModel)->getTable();

        return DB::table($table)
            ->join('mlb_games', "{$table}.game_id", '=', 'mlb_games.id')
            ->where('mlb_games.season', (int) date('Y'))
            ->where('mlb_games.status', 'STATUS_FINAL')
            ->groupBy("{$table}.team_id")
            ->selectRaw($this->mlbSelect("{$table}.team_id"))
            ->get()
            ->all();
    }

    private function mlbTeamAverages(int $teamId): ?object
    {
        return DB::table('mlb_team_stats')
            ->join('mlb_games', 'mlb_team_stats.game_id', '=', 'mlb_games.id')
            ->where('mlb_team_stats.team_id', $teamId)
            ->where('mlb_games.season', (int) date('Y'))
            ->where('mlb_games.status', 'STATUS_FINAL')
            ->selectRaw($this->mlbSelect('mlb_team_stats.team_id'))
            ->first();
    }

    private function mlbSelect(string $teamIdExpression): string
    {
        return "{$teamIdExpression} as team_id,
            COUNT(*) as games_played,
            AVG(runs) as runs_per_game,
            AVG(hits) as hits_per_game,
            AVG(home_runs) as home_runs_per_game,
            AVG(rbis) as rbis_per_game,
            AVG(walks) as walks_per_game,
            AVG(strikeouts) as strikeouts_per_game,
            AVG(stolen_bases) as stolen_bases_per_game,
            AVG(batting_average) as batting_average,
            AVG(doubles) as doubles_per_game,
            AVG(triples) as triples_per_game,
            AVG(errors) as errors_per_game,
            AVG(earned_runs) as earned_runs_per_game,
            AVG(era) as era";
    }

    /**
     * @return class-string<Model>
     */
    private function teamStatModel(SportContext $context): string
    {
        $teamStatModel = $context->models['team_stat'] ?? null;

        if (! is_string($teamStatModel) || ! is_subclass_of($teamStatModel, Model::class)) {
            abort(404, "Team stat season averages are not available for {$context->slug}.");
        }

        return $teamStatModel;
    }

    private function ensureSupported(SportContext $context): void
    {
        if (! in_array($context->slug, self::SUPPORTED_SPORTS, true)) {
            abort(404, "Team stat season averages are not available for {$context->slug}.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(SportContext $context, string $contract): array
    {
        return [
            'version' => 'v2',
            'sport' => $context->slug,
            'contract' => $contract,
            'tier' => [
                'mode' => 'sanitized_default',
                'allowed_field_groups' => ['identity', 'season_average_stats', 'freshness'],
                'withheld_field_groups' => ['raw_data', 'narrative', 'ai_analysis'],
            ],
            'freshness' => [],
            'warnings' => [],
        ];
    }
}
