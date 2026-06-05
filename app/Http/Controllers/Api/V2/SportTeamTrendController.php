<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportTeamTrendRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportTeamTrendQuery;
use Illuminate\Http\JsonResponse;

class SportTeamTrendController extends Controller
{
    public function show(
        string $sport,
        string $team,
        SportTeamTrendRequest $request,
        SportContextResolver $sports,
        SportTeamTrendQuery $trends,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        return response()->json([
            'data' => $trends->get($context, (int) $team, $filters, $request->user()),
            'meta' => [
                'version' => 'v2',
                'sport' => $context->slug,
                'contract' => 'sports.teams.trends.show',
                'team_id' => (int) $team,
                'filters' => $filters,
                'tier' => [],
                'freshness' => [],
                'warnings' => [],
            ],
        ]);
    }
}
