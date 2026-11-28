<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportInjuryIndexRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportInjuryQuery;
use Illuminate\Http\JsonResponse;

class SportInjuryController extends Controller
{
    public function index(
        string $sport,
        SportInjuryIndexRequest $request,
        SportContextResolver $sports,
        SportInjuryQuery $injuries,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $rows = $injuries->get($context, $filters);
        $summary = $injuries->summary($rows);
        $freshness = $injuries->freshness($context, $rows);
        $warnings = [];
        if ($freshness['is_stale'] === true) {
            $warnings[] = 'Injury data is stale and should not be used for a new betting decision.';
        } elseif ($freshness['is_stale'] === null) {
            $warnings[] = 'Provider observation freshness and complete coverage are unavailable; stored record updates do not prove a successful provider sync.';
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'version' => 'v2',
                'sport' => $context->slug,
                'contract' => 'sports.injuries.index',
                'filters' => (object) $filters,
                'total' => $summary['total'],
                'teams' => $summary['teams'],
                'tier' => (object) [],
                'freshness' => $freshness,
                'warnings' => $warnings,
            ],
        ]);
    }
}
