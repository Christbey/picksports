<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\Services\Predictions\PredictionAccessInspector;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PredictionAccessController extends Controller
{
    private const SPORTS = ['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba'];

    public function __construct(private readonly PredictionAccessInspector $inspector) {}

    public function __invoke(Request $request): Response
    {
        $sport = strtolower((string) $request->query('sport', 'nba'));
        if (! in_array($sport, self::SPORTS, true)) {
            $sport = 'nba';
        }

        return Inertia::render('Debug/PredictionAccess', [
            'sports' => self::SPORTS,
            'selectedSport' => $sport,
            'debug' => $this->inspector->inspect($request->user(), $sport),
        ]);
    }
}

