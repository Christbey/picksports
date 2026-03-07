<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Controller;
use App\Http\Resources\MLB\PlayoffForecastResource;
use App\Models\MLB\PlayoffForecast;
use App\Models\MLB\TeamMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayoffForecastController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'sort_by' => ['nullable', 'string'],
            'sort_direction' => ['nullable', 'string'],
        ]);

        $season = (int) (($validated['season'] ?? null) ?: config('mlb.season.default'));
        $allowedSorts = [
            'champion_probability',
            'playoff_make_probability',
            'world_series_probability',
            'league_championship_probability',
            'selection_score',
            'league_rank',
        ];

        $sortBy = (string) (($validated['sort_by'] ?? null) ?: 'champion_probability');
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'champion_probability';
        }

        $direction = strtolower((string) (($validated['sort_direction'] ?? null) ?: 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $forecasts = PlayoffForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderBy($sortBy, $direction)
            ->orderBy('playoff_make_probability', 'desc')
            ->get();

        $hasCurrentSeasonMetrics = TeamMetric::query()
            ->where('season', $season)
            ->exists();

        $projectionSourceSeason = $hasCurrentSeasonMetrics
            ? $season
            : TeamMetric::query()
                ->where('season', '<', $season)
                ->max('season');

        $usedRegression = ! $hasCurrentSeasonMetrics && $projectionSourceSeason !== null;

        $seasons = PlayoffForecast::query()
            ->select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->values();

        return response()->json([
            'data' => PlayoffForecastResource::collection($forecasts),
            'meta' => [
                'season' => $season,
                'available_seasons' => $seasons,
                'used_regression' => $usedRegression,
                'projection_source_season' => $projectionSourceSeason ? (int) $projectionSourceSeason : null,
            ],
        ]);
    }
}
