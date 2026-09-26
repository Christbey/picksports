<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\NFL\Game;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\NFL\NflHistoricalMarketEvidence;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NflMarketHistoryController extends Controller
{
    public function __invoke(string $sport, string $game, Request $request, SportContextResolver $sports,
        SportGameQuery $games, NflHistoricalMarketEvidence $evidence, SportsViewCache $cache): JsonResponse
    {
        abort_unless($sport === 'nfl', 404);
        $resolved = $games->find($sports->resolve($sport), $game, $request->user(), 'identity');
        abort_unless($resolved instanceof Game, 404);
        $input = $request->validate([
            'since' => ['sometimes', 'integer', 'min:2009', 'max:'.max(2009, (int) $resolved->season)],
            'home_line' => ['sometimes', 'required', 'numeric', 'between:-60,60', 'multiple_of:0.5'],
        ]);
        $since = (int) ($input['since'] ?? 2009);
        $line = isset($input['home_line']) ? (float) $input['home_line'] : null;
        $key = $cache->contextHash(['version' => NflHistoricalMarketEvidence::VERSION, 'game_id' => $resolved->id,
            'updated_at' => $resolved->updated_at?->toIso8601String(), 'since' => $since, 'home_line' => $line]);

        return response()->json([
            'data' => $cache->remember('nfl_market_history', $key, 120, fn () => $evidence->build($resolved, $since, $line)),
            'meta' => ['contract' => 'sports.games.market-history.show', 'cache_ttl_seconds' => 120],
        ]);
    }
}
