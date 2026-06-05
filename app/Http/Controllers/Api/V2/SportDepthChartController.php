<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Sports\DepthChartDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportDepthChartController extends Controller
{
    public function teamShow(
        string $sport,
        string $team,
        Request $request,
        SportContextResolver $sports,
        DepthChartDataService $depthCharts,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $payload = $depthCharts->forTeam(
            sport: $context->slug,
            teamModel: $this->model($context, 'team'),
            depthChartEntryModel: $this->model($context, 'depth_chart_entry'),
            playerStatModel: $this->model($context, 'player_stat'),
            gameModel: $this->model($context, 'game'),
            teamId: (int) $team,
            season: $request->integer('season') ?: null,
            seasonType: $request->query('season_type'),
            beforeDate: $request->string('before_date')->toString() ?: null,
        );

        return response()->json([
            'data' => $payload,
            'meta' => $this->meta($context, 'sports.teams.depth-charts.show', [
                'team_id' => (int) $team,
                'filters' => [
                    'season' => $request->integer('season') ?: null,
                    'season_type' => $request->query('season_type'),
                    'before_date' => $request->string('before_date')->toString() ?: null,
                ],
            ]),
        ]);
    }

    public function gameShow(
        string $sport,
        string $game,
        SportContextResolver $sports,
        DepthChartDataService $depthCharts,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $payload = $depthCharts->forGame(
            sport: $context->slug,
            gameModel: $this->model($context, 'game'),
            depthChartEntryModel: $this->model($context, 'depth_chart_entry'),
            playerStatModel: $this->model($context, 'player_stat'),
            gameId: (int) $game,
        );

        return response()->json([
            'data' => $payload,
            'meta' => $this->meta($context, 'sports.games.depth-charts.show', [
                'game_id' => (int) $game,
            ]),
        ]);
    }

    /**
     * @return class-string
     */
    private function model(SportContext $context, string $key): string
    {
        return $context->models[$key] ?? abort(404, "Depth charts are not supported for sport: {$context->slug}");
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function meta(SportContext $context, string $contract, array $extra = []): array
    {
        return [
            'version' => 'v2',
            'sport' => $context->slug,
            'contract' => $contract,
            'tier' => [
                'mode' => 'sanitized_default',
                'allowed_field_groups' => ['identity', 'depth_chart', 'stat_summary', 'freshness'],
                'withheld_field_groups' => ['raw_data', 'narrative', 'ai_analysis'],
            ],
            'freshness' => [],
            'warnings' => [],
        ] + $extra;
    }
}
