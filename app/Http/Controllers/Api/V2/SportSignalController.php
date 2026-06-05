<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportSignalIndexRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportSignalQuery;
use Illuminate\Http\JsonResponse;

class SportSignalController extends Controller
{
    public function index(
        string $sport,
        SportSignalIndexRequest $request,
        SportContextResolver $sports,
        SportSignalQuery $signals,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        return response()->json([
            'data' => $signals->get($context, $filters),
            'meta' => [
                'version' => 'v2',
                'sport' => $context->slug,
                'contract' => 'sports.signals.index',
                'filters' => $this->metaFilters($filters),
                'tier' => [],
                'freshness' => [],
                'warnings' => [],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function metaFilters(array $filters): array
    {
        if (isset($filters['as_of_date']) && is_object($filters['as_of_date']) && method_exists($filters['as_of_date'], 'toDateString')) {
            $filters['as_of_date'] = $filters['as_of_date']->toDateString();
        }

        return $filters;
    }
}
