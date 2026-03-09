<?php

namespace App\Http\Controllers;

use App\Http\Resources\BettingRecommendationResource;
use App\Support\SportsViewCache;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BettingRecommendationsController extends Controller
{
    public function __construct(
        protected PlayerPropAnalyzer $analyzer,
        protected SportsViewCache $sportsViewCache,
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
            'market' => ['nullable', 'string', 'max:100'],
        ]);

        $requestedDate = $validated['date'] ?? null;
        $dateFilter = $requestedDate;
        $gameFilter = isset($validated['game']) ? (int) $validated['game'] : null;
        $marketFilter = $validated['market'] ?? null;

        $cacheKey = $this->sportsViewCache->contextHash([
            'component' => $component,
            'sport' => $sport,
            'requested_date' => $requestedDate,
            'game' => $gameFilter,
            'market' => $marketFilter,
            'today' => now()->toDateString(),
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: 'player_props_page',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.player_props_page_seconds', 60),
            resolver: function () use ($sport, $dateFilter, $gameFilter, $marketFilter): array {
                // NBA page default: load today's board first when no explicit date filter is provided.
                $resolvedDateFilter = $dateFilter;
                $dates = $this->analyzer->getAvailableDatesForSport($sport);
                if ($sport === 'NBA' && $resolvedDateFilter === null && $dates->isNotEmpty()) {
                    $today = Carbon::today()->toDateString();
                    $dateValues = $dates->pluck('value');

                    if ($dateValues->contains($today)) {
                        $resolvedDateFilter = $today;
                    } else {
                        $futureDates = $dateValues->filter(fn ($d) => is_string($d) && $d > $today)->values();
                        if ($futureDates->isNotEmpty()) {
                            $resolvedDateFilter = (string) $futureDates->first();
                        } else {
                            $resolvedDateFilter = (string) $dateValues->last();
                        }
                    }
                }

                // Use lower minimum games threshold since we have limited historical data
                $recommendations = $this->analyzer->analyzeProps(
                    sport: $sport,
                    minGames: 3,
                    dateFilter: $resolvedDateFilter,
                    gameFilter: $gameFilter,
                    marketFilter: $marketFilter
                );

                // Get available games for selected date
                $games = $this->analyzer->getAvailableGamesForSport($sport, $resolvedDateFilter);
                $markets = $this->analyzer->getAvailableMarketsForSport($sport, $resolvedDateFilter, $gameFilter);

                return [
                    'sport' => $sport,
                    'recommendations' => BettingRecommendationResource::collection($recommendations)->resolve(),
                    'dates' => $dates,
                    'games' => $games,
                    'markets' => $markets,
                    'filters' => [
                        'date' => $resolvedDateFilter,
                        'game' => $gameFilter,
                        'market' => $marketFilter,
                    ],
                ];
            },
        );

        return Inertia::render($component, $payload);
    }
}
