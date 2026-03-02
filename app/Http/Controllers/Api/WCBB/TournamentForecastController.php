<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\TournamentForecastResource;
use App\Models\WCBB\TournamentForecast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentForecastController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $season = (int) ($request->integer('season') ?: config('wcbb.season.default'));
        $allowedSorts = [
            'champion_probability',
            'tournament_make_probability',
            'auto_bid_probability',
            'at_large_probability',
            'bid_thief_probability',
            'selection_score',
        ];

        $sortBy = (string) ($request->query('sort_by', 'champion_probability'));
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'champion_probability';
        }

        $direction = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $forecasts = TournamentForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderBy($sortBy, $direction)
            ->orderBy('tournament_make_probability', 'desc')
            ->get();

        $seasons = TournamentForecast::query()
            ->select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->values();

        return response()->json([
            'data' => TournamentForecastResource::collection($forecasts),
            'meta' => [
                'season' => $season,
                'available_seasons' => $seasons,
            ],
        ]);
    }
}
