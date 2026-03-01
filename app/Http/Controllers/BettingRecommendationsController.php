<?php

namespace App\Http\Controllers;

use App\Http\Resources\BettingRecommendationResource;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BettingRecommendationsController extends Controller
{
    public function __construct(
        protected PlayerPropAnalyzer $analyzer
    ) {}

    public function nba(Request $request): Response
    {
        return $this->renderPlayerProps('NBA', $request, 'NBA/PlayerProps');
    }

    public function mlb(Request $request): Response
    {
        return $this->renderPlayerProps('MLB', $request, 'MLB/PlayerProps');
    }

    public function nfl(Request $request): Response
    {
        return $this->renderPlayerProps('NFL', $request, 'NFL/PlayerProps');
    }

    public function cbb(Request $request): Response
    {
        return $this->renderPlayerProps('CBB', $request, 'CBB/PlayerProps');
    }

    protected function renderPlayerProps(string $sport, Request $request, string $component): Response
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'game' => ['nullable', 'integer'],
        ]);

        $dateFilter = $validated['date'] ?? null;
        $gameFilter = isset($validated['game']) ? (int) $validated['game'] : null;

        // Use lower minimum games threshold since we have limited historical data
        $recommendations = $this->analyzer->analyzeProps(
            sport: $sport,
            minGames: 3,
            dateFilter: $dateFilter,
            gameFilter: $gameFilter
        );

        // Get available dates and games for filter dropdowns
        $dates = $this->analyzer->getAvailableDatesForSport($sport);
        $games = $this->analyzer->getAvailableGamesForSport($sport, $dateFilter);

        return Inertia::render($component, [
            'sport' => $sport,
            'recommendations' => BettingRecommendationResource::collection($recommendations)->resolve(),
            'dates' => $dates,
            'games' => $games,
            'filters' => [
                'date' => $dateFilter,
                'game' => $gameFilter,
            ],
        ]);
    }
}
