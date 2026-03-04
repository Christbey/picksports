<?php

namespace App\Http\Controllers;

use App\Http\Resources\BettingRecommendationResource;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Carbon\Carbon;
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

        $requestedDate = $validated['date'] ?? null;
        $dateFilter = $requestedDate;
        $gameFilter = isset($validated['game']) ? (int) $validated['game'] : null;

        // NBA page default: load today's board first when no explicit date filter is provided.
        $dates = $this->analyzer->getAvailableDatesForSport($sport);
        if ($sport === 'NBA' && $dateFilter === null && $dates->isNotEmpty()) {
            $today = Carbon::today()->toDateString();
            $dateValues = $dates->pluck('value');

            if ($dateValues->contains($today)) {
                $dateFilter = $today;
            } else {
                $futureDates = $dateValues->filter(fn ($d) => is_string($d) && $d > $today)->values();
                if ($futureDates->isNotEmpty()) {
                    $dateFilter = (string) $futureDates->first();
                } else {
                    $dateFilter = (string) $dateValues->last();
                }
            }
        }

        // Use lower minimum games threshold since we have limited historical data
        $recommendations = $this->analyzer->analyzeProps(
            sport: $sport,
            minGames: 3,
            dateFilter: $dateFilter,
            gameFilter: $gameFilter
        );

        // Get available games for selected date
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
