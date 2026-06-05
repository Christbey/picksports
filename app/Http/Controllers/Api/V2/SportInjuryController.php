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

        return response()->json([
            'data' => $rows,
            'meta' => [
                'version' => 'v2',
                'sport' => $context->slug,
                'contract' => 'sports.injuries.index',
                'filters' => $filters,
                'total' => $summary['total'],
                'teams' => $summary['teams'],
                'tier' => [],
                'freshness' => [],
                'warnings' => [],
            ],
        ]);
    }
}
