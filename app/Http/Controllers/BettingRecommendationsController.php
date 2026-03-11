<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BettingRecommendationsController extends Controller
{
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

        return Inertia::render($component, [
            'sport' => $sport,
            'filters' => [
                'date' => $validated['date'] ?? null,
                'game' => isset($validated['game']) ? (int) $validated['game'] : null,
                'market' => $validated['market'] ?? null,
            ],
        ]);
    }
}
