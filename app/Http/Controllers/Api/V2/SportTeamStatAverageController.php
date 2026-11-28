<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportTeamSeasonAverageRequest;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportTeamSeasonAverageQuery;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;
use App\Support\Api\ApiV2ErrorResponse;
use Illuminate\Http\JsonResponse;

class SportTeamStatAverageController extends Controller
{
    private const SUPPORTED_SPORTS = ['nba', 'cbb', 'wcbb', 'wnba', 'mlb'];

    public function index(
        string $sport,
        SportTeamSeasonAverageRequest $request,
        SportContextResolver $sports,
        BasketballTeamSeasonAveragesService $basketballAverages,
        SportTeamSeasonAverageQuery $averages,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $this->ensureSupported($context);
        $result = $averages->allWithScope($context, $basketballAverages, $request->validatedFilters());

        return response()->json([
            'data' => $result['data'],
            'meta' => $this->meta($context, 'sports.stats.team.season-averages.index') + ['scope' => $result['scope']],
        ]);
    }

    public function teamShow(
        string $sport,
        string $team,
        SportTeamSeasonAverageRequest $request,
        SportContextResolver $sports,
        BasketballTeamSeasonAveragesService $basketballAverages,
        SportTeamSeasonAverageQuery $averages,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $this->ensureSupported($context);
        $teamId = (int) $team;
        $result = $averages->teamWithScope($context, $basketballAverages, $teamId, $request->validatedFilters());
        $payload = $result['data'];

        if (! $payload) {
            $response = app(ApiV2ErrorResponse::class)->make($request, 404, 'not_found', 'Team stat season averages are not available.');
            $response->setData([...$response->getData(true),
                'meta' => ['version' => 'v2', 'sport' => $context->slug, 'contract' => 'sports.teams.stats.season-averages.show', 'team_id' => $teamId, 'scope' => $result['scope']],
            ]);

            return $response;
        }

        return response()->json([
            'data' => $payload,
            'meta' => $this->meta($context, 'sports.teams.stats.season-averages.show') + [
                'team_id' => $teamId, 'scope' => $result['scope'],
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
            'freshness' => (object) [],
            'warnings' => [],
        ];
    }
}
