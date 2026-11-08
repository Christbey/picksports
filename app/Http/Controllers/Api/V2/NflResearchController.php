<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\NFL\ResearchRevision;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NflResearchController extends Controller
{
    public function __invoke(string $sport, string $game, Request $request, SportContextResolver $sports, SportGameQuery $games): JsonResponse
    {
        abort_unless($sport === 'nfl', 404);
        $resolved = $games->find($sports->resolve($sport), $game, $request->user(), 'identity');
        $ids = ResearchRevision::where('game_id', $resolved->getKey())->latest('id')->limit(20)->pluck('id');
        $revisions = ResearchRevision::query()->whereKey($ids)->get([
            'id', 'created_at', 'baseline', 'revised', 'brief', 'market', 'evaluation',
        ])->keyBy('id');
        $rows = $ids->map(fn ($id) => $revisions->get($id))->filter()->values();

        return response()->json([
            'data' => [
                'game_id' => $resolved->getKey(),
                'revisions' => $rows->map(fn (ResearchRevision $revision): array => $revision->only([
                    'id', 'created_at', 'baseline', 'revised', 'brief', 'market', 'evaluation',
                ])),
            ],
            'meta' => ['version' => 'v2', 'sport' => 'nfl', 'contract' => 'sports.games.research.show'],
        ]);
    }
}
