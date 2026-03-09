<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CBB\TournamentForecastResource;
use App\Models\CBB\TournamentForecast;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentForecastController extends Controller
{
    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $season = (int) ($request->integer('season') ?: config('cbb.season.default'));
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

        $data = TournamentForecastResource::collection($forecasts)->resolve($request);
        $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('cbb', $season);
        $data = array_map(function (array $row) use ($marketOddsByTeam): array {
            $teamId = (int) ($row['team_id'] ?? 0);
            $row['market_odds'] = $marketOddsByTeam[$teamId] ?? null;

            return $row;
        }, $data);
        $data = $this->futuresEdgeService->annotate($data, 'champion_probability');

        return response()->json([
            'data' => $data,
            'meta' => [
                'season' => $season,
                'available_seasons' => $seasons,
            ],
        ]);
    }
}
