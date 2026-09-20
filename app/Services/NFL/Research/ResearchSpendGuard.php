<?php

namespace App\Services\NFL\Research;

use App\Models\AiGeneration;
use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ResearchSpendGuard
{
    /** Atomically reserve estimated spend before creating a potentially paid request. */
    public function reserve(Game $game, string $fingerprint, string $provider, string $model, callable $start): AiGeneration
    {
        if (! Schema::hasTable('ai_generations')) {
            throw new ResearchDeferred('research_usage_ledger_missing');
        }
        if ($provider !== 'openai' || $model !== config('ai.features.nfl_game_context_research.pricing.model')) {
            throw new ResearchDeferred('research_model_pricing_unconfigured');
        }

        try {
            return Cache::lock('nfl-research-spend-reservation', 15)->block(3, function () use ($game, $fingerprint, $start) {
                $rows = AiGeneration::query()->where('purpose', 'nfl_game_context_research')
                    ->where('started_at', '>=', now()->subDay())
                    ->get(['id', 'context_id', 'cost_usd', 'metadata', 'started_at']);
                $gameRows = $rows->where('context_id', (string) $game->id);
                $latest = $gameRows->sortByDesc('id')->first();
                $reservation = max(0.01, (float) config('nfl_research.cost_control.reservation_usd', 0.15));
                $cost = fn ($row): float => $this->accountedCost($row);
                if ($rows->sum($cost) + $reservation > (float) config('nfl_research.cost_control.daily_budget_usd', 5)) {
                    throw new ResearchDeferred('research_daily_budget_reached');
                }
                if ($gameRows->sum($cost) + $reservation > (float) config('nfl_research.cost_control.game_daily_budget_usd', 0.75)) {
                    throw new ResearchDeferred('research_game_daily_budget_reached');
                }
                $limit = max(1, (int) config('nfl_research.cost_control.game_daily_attempts', 8));
                $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
                $pregameReserve = $kickoff && $kickoff->isFuture()
                    && $kickoff->lte(now()->addHours(max(0, (int) config('nfl_research.cost_control.pregame_reserve_hours', 12))))
                    && in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true);
                if ($pregameReserve) {
                    $limit += max(0, (int) config('nfl_research.cost_control.pregame_reserved_attempts', 4));
                }
                if ($gameRows->count() >= $limit) {
                    throw new ResearchDeferred('research_game_attempt_limit_reached');
                }
                if ($latest) {
                    $sameEvidence = data_get($latest->metadata, 'research_fingerprint') === $fingerprint;
                    $minutes = max(1, (int) config('nfl_research.cost_control.minimum_interval_minutes', 15));
                    if ($sameEvidence) {
                        $minutes = max($minutes, min(
                            app(ResearchRefreshPolicy::class)->freshnessMinutes($game),
                            (int) config('nfl_research.cost_control.retry_unchanged_minutes', 360),
                        ));
                    }
                    if ($latest->started_at->gt(now()->subMinutes($minutes))) {
                        throw new ResearchDeferred('research_retry_not_due');
                    }
                }

                return $start(['reserved_cost_usd' => $reservation, 'research_fingerprint' => $fingerprint,
                    'attempt_limit' => $limit, 'pregame_reserve_available' => (bool) $pregameReserve]);
            });
        } catch (LockTimeoutException) {
            throw new ResearchDeferred('research_budget_lock_busy');
        }
    }

    public function accountedCost(AiGeneration $row): float
    {
        return $row->cost_usd !== null ? (float) $row->cost_usd : max(
            0.01,
            (float) config('nfl_research.cost_control.reservation_usd', 0.15),
            (float) data_get($row->metadata, 'reserved_cost_usd', 0),
        );
    }
}
