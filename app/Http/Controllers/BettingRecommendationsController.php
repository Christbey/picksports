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
        return $this->renderPlayerProps('NBA', $request);
    }

    public function mlb(Request $request): Response
    {
        return $this->renderPlayerProps('MLB', $request);
    }

    public function nfl(Request $request): Response
    {
        return $this->renderPlayerProps('NFL', $request);
    }

    public function cbb(Request $request): Response
    {
        return $this->renderPlayerProps('CBB', $request);
    }

    protected function renderPlayerProps(string $sport, Request $request): Response
    {
        $dateFilter = $request->get('date');
        $gameFilter = $request->get('game');

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

        return Inertia::render('PlayerProps', [
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
