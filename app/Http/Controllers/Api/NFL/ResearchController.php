<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Controller;
use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use Illuminate\Http\JsonResponse;

class ResearchController extends Controller
{
    public function show(Game $game): JsonResponse
    {
        $rows = ResearchRevision::where('game_id', $game->id)->latest('id')->limit(20)->get();

        return response()->json(['game_id' => $game->id, 'revisions' => $rows->map(fn ($r) => ['id' => $r->id, 'created_at' => $r->created_at, 'baseline' => $r->baseline, 'revised' => $r->revised, 'brief' => $r->brief, 'market' => $r->market, 'evaluation' => $r->evaluation])]);
    }
}
