<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportForecastIndexRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportForecastQuery;
use Illuminate\Http\JsonResponse;

class SportForecastController extends Controller
{
    public function index(
        string $sport,
        SportForecastIndexRequest $request,
        SportContextResolver $sports,
        SportForecastQuery $forecasts,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $payload = $forecasts->get($context, $filters);

        $payload['meta'] = array_merge([
            'version' => 'v2',
            'sport' => $context->slug,
            'contract' => 'sports.forecasts.index',
            'filters' => $filters,
            'tier' => [],
            'freshness' => [],
            'warnings' => [],
        ], $payload['meta'] ?? []);

        return response()->json($payload);
    }
}
