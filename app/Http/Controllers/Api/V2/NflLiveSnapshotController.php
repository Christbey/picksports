<?php

namespace App\Http\Controllers\Api\V2;

use App\Actions\NFL\UpdateLivePrediction;
use App\Http\Controllers\Controller;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NflLiveSnapshotController extends Controller
{
    public function __invoke(string $sport, string $game, Request $request, SportContextResolver $sports, SportGameQuery $games, UpdateLivePrediction $calculator): JsonResponse
    {
        abort_unless($sport === 'nfl', 404);
        $context = $sports->resolve($sport);
        $data = DB::transaction(function () use ($context, $game, $request, $games, $calculator): array {
            $resolved = $games->find($context, $game, $request->user(), 'identity');
            $resolved->load('prediction');

            return [
                'game' => $resolved->only(['id', 'status', 'home_score', 'away_score', 'period', 'game_clock', 'home_linescores', 'away_linescores']),
                'projection' => $calculator->preview($resolved),
                'source_updated_at' => $resolved->updated_at?->toIso8601String(),
                'generated_at' => now()->toIso8601String(),
                'provisional' => true,
                'model_kind' => 'score_clock_heuristic',
                'calibrated' => false,
                'clock_scope' => $resolved->period > 4 ? 'current_overtime_period' : 'regulation',
                'warning' => 'Experimental score-and-clock estimate; not calibrated and not a live sportsbook edge. Possession, field position, down/distance and timeouts are not modeled.',
            ];
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
