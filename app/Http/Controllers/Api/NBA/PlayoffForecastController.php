<?php

namespace App\Http\Controllers\Api\NBA;

use App\Actions\NBA\GeneratePlayoffForecast;
use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\PlayoffForecastResource;
use App\Models\NBA\PlayoffForecast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayoffForecastController extends Controller
{
    public function index(Request $request, GeneratePlayoffForecast $generatePlayoffForecast): JsonResponse
    {
        $season = (int) ($request->integer('season') ?: config('nba.season.default'));
        $allowedSorts = [
            'champion_probability',
            'playoff_make_probability',
            'nba_finals_probability',
            'conference_finals_probability',
            'selection_score',
            'conference_rank',
        ];

        $sortBy = (string) ($request->query('sort_by', 'champion_probability'));
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'champion_probability';
        }

        $direction = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $forecasts = PlayoffForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderBy($sortBy, $direction)
            ->orderBy('playoff_make_probability', 'desc')
            ->get();

        if ($forecasts->isEmpty()) {
            $generatePlayoffForecast->execute($season);
            $forecasts = PlayoffForecast::query()
                ->with('team')
                ->where('season', $season)
                ->orderBy($sortBy, $direction)
                ->orderBy('playoff_make_probability', 'desc')
                ->get();
        }

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
                'playoff_teams_per_conference' => (int) config('nba.playoff_forecast.playoff_teams_per_conference', 8),
                'play_in_teams_per_conference' => (int) config('nba.playoff_forecast.play_in_teams_per_conference', 10),
            ],
        ]);
    }
}
