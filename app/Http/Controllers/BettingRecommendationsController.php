<?php

namespace App\Http\Controllers;

use App\Http\Requests\BettingRecommendationsRenderPlayerPropsRequest;
use Inertia\Inertia;
use Inertia\Response;

class BettingRecommendationsController extends Controller
{
    public function nba(BettingRecommendationsRenderPlayerPropsRequest $request): Response
    {
        return $this->renderPlayerProps('NBA', $request, 'NBA/PlayerProps');
    }

    public function mlb(BettingRecommendationsRenderPlayerPropsRequest $request): Response
    {
        return $this->renderPlayerProps('MLB', $request, 'MLB/PlayerProps');
    }

    public function nfl(BettingRecommendationsRenderPlayerPropsRequest $request): Response
    {
        return $this->renderPlayerProps('NFL', $request, 'NFL/PlayerProps');
    }

    public function cfb(BettingRecommendationsRenderPlayerPropsRequest $request): Response
    {
        return $this->renderPlayerProps('CFB', $request, 'CFB/PlayerProps');
    }

    public function cbb(BettingRecommendationsRenderPlayerPropsRequest $request): Response
    {
        return $this->renderPlayerProps('CBB', $request, 'CBB/PlayerProps');
    }

    public function wnba(BettingRecommendationsRenderPlayerPropsRequest $request): Response
    {
        return $this->renderPlayerProps('WNBA', $request, 'WNBA/PlayerProps');
    }

    protected function renderPlayerProps(string $sport, BettingRecommendationsRenderPlayerPropsRequest $request, string $component): Response
    {
        $validated = $request->validated();

        return Inertia::render($component, [
            'sport' => $sport,
            'filters' => [
                'date' => $validated['date'] ?? null,
                'view' => $validated['view'] ?? 'recommendations',
                'game' => isset($validated['game']) ? (int) $validated['game'] : null,
                'market' => $validated['market'] ?? null,
            ],
        ]);
    }
}
