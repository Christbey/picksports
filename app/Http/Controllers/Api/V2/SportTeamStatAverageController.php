<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportTeamSeasonAverageQuery;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;
use Illuminate\Http\JsonResponse;

class SportTeamStatAverageController extends Controller
{
    private const SUPPORTED_SPORTS = ['nba', 'cbb', 'wcbb', 'wnba', 'mlb'];

    public function index(
        string $sport,
        SportContextResolver $sports,
        BasketballTeamSeasonAveragesService $basketballAverages,
        SportTeamSeasonAverageQuery $averages,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $this->ensureSupported($context);

        return response()->json([
            'data' => $averages->allAverages($context, $basketballAverages),
            'meta' => $this->meta($context, 'sports.stats.team.season-averages.index'),
        ]);
    }

    public function teamShow(
        string $sport,
        string $team,
        SportContextResolver $sports,
        BasketballTeamSeasonAveragesService $basketballAverages,
        SportTeamSeasonAverageQuery $averages,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $this->ensureSupported($context);
        $teamId = (int) $team;
        $payload = $averages->teamAverages($context, $basketballAverages, $teamId);

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
