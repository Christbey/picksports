<?php

namespace App\Services\CFB\Live;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\CFB\Predictions\CfbStoredPregameQuote;

class LiveSnapshotRecorder
{
    public function baseline(Game $game): ?array
    {
        // Start a new baseline contract without changing any earlier snapshot.
        $existing = LivePredictionSnapshot::where('game_id', $game->id)
            ->where('pregame->baseline_contract', 'canonical-or-closing-v2')->oldest('id')->first();
        if ($existing) {
            return $existing->pregame;
        }
        $kickoff = ($game->sportEvent?->starts_at ?? CfbPropEligibility::kickoff($game))->copy()->setTimezone(config('app.timezone'));
        $prediction = CanonicalPrediction::where('sport', 'cfb')->where('sport_event_id', $game->sport_event_id)
            ->where('phase', 'pregame')->where('publication_state', 'published')
            ->where('generated_at', '<', $kickoff)->where('published_at', '<', $kickoff)
            ->where('created_at', '<', $kickoff)->with('markets')->latest('generated_at')->first();
        $base = ['baseline_contract' => 'canonical-or-closing-v2', 'prediction_id' => $game->prediction?->id,
            'canonical_prediction_id' => $prediction?->id, 'spread' => null, 'total' => null,
            'home_win_probability' => null, 'model_version' => null, 'source' => 'unavailable',
            'captured_at' => now()->toIso8601String()];
        if ($prediction && data_get($prediction->output_metadata, 'input_quality.qualified') === true) {
            $spread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home')?->projected_line;
            $total = $prediction->markets->first(fn ($m) => $m->market_type === 'total' && $m->selection === 'combined')?->projected_line;
            if (is_numeric($spread) && is_numeric($total) && $total >= abs($spread)) {
                return [...$base, 'source' => 'canonical_pregame', 'spread' => -(float) $spread, 'total' => (float) $total,
                    'model_version' => $prediction->model_version, 'generated_at' => $prediction->generated_at->toIso8601String(),
                    'home_win_probability' => $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')?->probability];
            }
        }
        // An explicit market prior permits live tracking for missing team/QB data.
        // It is never represented as an independent pregame model prediction.
        $quotes = app(CfbStoredPregameQuote::class);
        $spread = $quotes->latest($game->id, 'spreads', 'home', $kickoff);
        $total = $quotes->latest($game->id, 'totals', 'over', $kickoff);
        if ($spread && $total && is_numeric($spread->line) && is_numeric($total->line) && $total->line >= abs($spread->line)) {
            return [...$base, 'source' => 'stored_closing_market', 'spread' => -(float) $spread->line,
                'total' => (float) $total->line, 'model_version' => 'closing-market-prior-v1',
                'quote_ids' => ['spread' => $spread->id, 'total' => $total->id],
                'risk_flags' => data_get($prediction?->output_metadata, 'input_quality.risk_flags', ['missing_canonical_pregame']),
                'home_win_probability' => 1 / (1 + exp((float) $spread->line / 6))];
        }
        // Compatibility only for games not yet linked to the canonical event catalog.
        $legacy = $game->prediction;
        if (! $game->sport_event_id && $legacy && $legacy->updated_at->lt($kickoff)) {
            return [...$base, 'source' => 'legacy_pregame', 'spread' => $legacy->predicted_spread !== null ? (float) $legacy->predicted_spread : null,
                'total' => $legacy->predicted_total !== null ? (float) $legacy->predicted_total : null,
                'home_win_probability' => $legacy->win_probability !== null ? (float) $legacy->win_probability : null,
                'model_version' => $legacy->model_version];
        }

        return $base;
    }

    public function record(Game $game, array $baseline, ?array $projection, string $status, string $source = 'scoreboard', array $markets = [], array $props = []): LivePredictionSnapshot
    {
        $state = ['status' => $game->status, 'period' => $game->period, 'clock' => $game->game_clock,
            'home_score' => $game->home_score, 'away_score' => $game->away_score];
        $hash = hash('sha256', json_encode([$game->id, now()->format('Y-m-d H:i'), $state, $baseline, $projection, $status, $source, $markets, $props]));

        return LivePredictionSnapshot::firstOrCreate(['state_hash' => $hash], [
            'game_id' => $game->id, 'prediction_id' => $baseline['prediction_id'], 'source' => $source,
            'pregame' => $baseline, 'state' => $state, 'projection' => $projection, 'markets' => $markets, 'props' => $props,
            'status' => $status, 'observed_at' => now(),
        ]);
    }
}
