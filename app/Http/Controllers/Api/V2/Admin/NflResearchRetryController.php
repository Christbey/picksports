<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\NFL\RetryGameResearch;
use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class NflResearchRetryController extends Controller
{
    public function show(Game $game): JsonResponse
    {
        return response()->json(['data' => Cache::get(RetryGameResearch::key($game->id), ['status' => 'idle'])]);
    }

    public function store(Game $game, Request $request): JsonResponse
    {
        abort_unless(config('nfl_research.enabled') && config('ai.features.nfl_game_context_research.enabled', true), 422, 'NFL research is disabled.');
        $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        abort_unless($kickoff?->isFuture() && in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true), 422, 'Research can only be retried before kickoff.');
        $key = RetryGameResearch::key($game->id);
        $lock = Cache::lock($key.':lock', 1800);
        if (! $lock->get()) {
            return response()->json(['data' => Cache::get($key, ['status' => 'queued']), 'message' => 'A research retry is already queued or running.'], 202);
        }
        $state = ['run_id' => $lock->owner(), 'status' => 'queued', 'message' => 'Research retry queued.',
            'requested_by' => $request->user()->id, 'updated_at' => now()->toIso8601String()];
        Cache::put($key, $state, now()->addMinutes(30));
        try {
            RetryGameResearch::dispatch($game->id, $request->user()->id, $lock->owner());
        } catch (Throwable $exception) {
            Cache::forget($key);
            $lock->release();
            throw $exception;
        }

        return response()->json(['data' => Cache::get($key, $state)], 202);
    }
}
