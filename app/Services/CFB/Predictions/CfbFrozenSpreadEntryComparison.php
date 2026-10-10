<?php

namespace App\Services\CFB\Predictions;

use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\EventInputSnapshot;
use App\Models\MarketQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only diagnostics. Candidate side selection is not a qualified betting recommendation. */
class CfbFrozenSpreadEntryComparison
{
    public function compare(BetDecision $decision, Game $game): array
    {
        $grade = app(CfbFrozenDecisionPerformance::class)->grade($decision, $game);
        if ($grade['grade'] === null || ! is_numeric($decision->line) || ! in_array($decision->side, ['home', 'away'], true)) {
            return ['excluded' => 'missing_verified_entry_or_final_result'];
        }
        $predictionId = data_get($decision->feature_snapshot, 'canonical_prediction_id');
        $snapshotId = data_get($decision->feature_snapshot, 'event_input_snapshot_id');
        $prediction = $predictionId ? CanonicalPrediction::with('markets')->find($predictionId) : null;
        $snapshot = $snapshotId ? EventInputSnapshot::find($snapshotId) : null;
        $kickoff = $game->sportEvent?->starts_at;
        $run = $prediction ? DB::table('calculation_runs')->where('id', $prediction->calculation_run_id)->first(['event_input_snapshot_id', 'status']) : null;
        $linkedSnapshotId = $run?->event_input_snapshot_id;
        if (! $prediction || ! $snapshot || ! $kickoff || (int) $linkedSnapshotId !== (int) $snapshot->id
            || $run?->status !== 'succeeded'
            || ! in_array($prediction->publication_state, ['published', 'superseded'], true)
            || ! $prediction->published_at || $prediction->published_at->gt($decision->decided_at)
            || $prediction->sport !== 'cfb' || $prediction->phase !== 'pregame' || ! $prediction->generated_at
            || $prediction->generated_at->gt($decision->decided_at)
            || $snapshot->sport_event_id !== $game->sport_event_id || $prediction->sport_event_id !== $game->sport_event_id
            || $snapshot->sport !== 'cfb' || $snapshot->phase !== 'pregame' || $snapshot->pregame_safety_status !== 'verified' || ! $snapshot->captured_at
            || $snapshot->captured_at->gt($decision->decided_at) || $snapshot->captured_at->gte($kickoff)
            || $snapshot->latest_source_available_at?->gt($snapshot->captured_at)
            || data_get($decision->feature_snapshot, 'content_hash') !== $snapshot->content_hash) {
            return ['excluded' => 'snapshot_not_linked_or_available_at_entry'];
        }
        $actualMargin = (float) $game->home_score - (float) $game->away_score;
        $baseline = app(CfbFrozenBaselineComparison::class)->evaluate($snapshot->inputs, $snapshot->captured_at, $kickoff, $actualMargin);
        $fpi = $baseline['margins']['fpi'] ?? null;
        $spread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home');
        if (! is_numeric($fpi) || ! is_numeric($spread?->projected_line)) {
            return ['excluded' => 'missing_contemporaneous_fpi_or_official_margin'];
        }
        $homeLine = $decision->side === 'home' ? (float) $decision->line : -(float) $decision->line;
        $prices = [$decision->side => is_numeric($decision->price) && abs((int) $decision->price) >= 100 ? (int) $decision->price : null];
        $frozenEntry = (array) data_get($decision->market_snapshot, 'quote', []);
        $frozenOpposite = (array) ($frozenEntry['opposite_quote'] ?? []);
        try {
            $frozenTimesSafe = collect(['captured_at', 'provider_observed_at'])->every(fn ($key) => is_string($frozenOpposite[$key] ?? null) && CarbonImmutable::parse($frozenOpposite[$key])
                ->betweenIncluded($decision->decided_at->copy()->subMinutes(60), $decision->decided_at))
                && is_string($frozenOpposite['stored_at'] ?? null)
                && CarbonImmutable::parse($frozenOpposite['stored_at'])->lte($decision->decided_at);
        } catch (\Throwable) {
            $frozenTimesSafe = false;
        }
        if ($frozenTimesSafe && is_numeric($frozenEntry['snapshot_id'] ?? null)
            && ($frozenEntry['side'] ?? null) === $decision->side
            && ($frozenEntry['bookmaker'] ?? null) === $decision->bookmaker
            && is_numeric($frozenEntry['line'] ?? null) && abs((float) $frozenEntry['line'] - (float) $decision->line) < .001
            && is_array($frozenEntry['contract'] ?? null) && ($frozenOpposite['snapshot_id'] ?? null) === ($frozenEntry['snapshot_id'] ?? null)
            && ($frozenOpposite['bookmaker'] ?? null) === $decision->bookmaker
            && ($frozenOpposite['side'] ?? null) === ($decision->side === 'home' ? 'away' : 'home')
            && is_numeric($frozenOpposite['line'] ?? null) && abs((float) $frozenOpposite['line'] + (float) $decision->line) < .001
            && ($frozenOpposite['contract'] ?? null) === ($frozenEntry['contract'] ?? null)
            && is_numeric($frozenOpposite['price'] ?? null) && abs((int) $frozenOpposite['price']) >= 100) {
            $prices[$frozenOpposite['side']] = (int) $frozenOpposite['price'];
        }
        $entryQuoteId = data_get($decision->market_snapshot, 'quote.quote_id');
        $entry = $entryQuoteId ? MarketQuote::find($entryQuoteId) : null;
        if (count($prices) < 2 && $entry && $entry->sport === 'cfb' && $entry->game_id === $game->id && $entry->side === $decision->side
            && $entry->game_table === 'cfb_games' && $entry->market_key === 'spreads'
            && $entry->bookmaker_key === $decision->bookmaker && abs((float) $entry->line - (float) $decision->line) < .001
            && $entry->is_pregame && $entry->captured_at && $entry->created_at
            && $entry->captured_at->betweenIncluded($decision->decided_at->copy()->subMinutes(60), $decision->decided_at)
            && $entry->created_at->lte($decision->decided_at)) {
            $opposite = MarketQuote::where('game_odds_snapshot_id', $entry->game_odds_snapshot_id)
                ->where('sport', 'cfb')->where('game_id', $game->id)->where('bookmaker_key', $entry->bookmaker_key)
                ->where('market_key', 'spreads')->where('side', $decision->side === 'home' ? 'away' : 'home')
                ->where('is_pregame', true)->where('captured_at', '<=', $decision->decided_at)
                ->where('created_at', '<=', $decision->decided_at)->first();
            $observed = data_get($opposite?->metadata, 'provider_observed_at');
            try {
                $validObservation = is_string($observed) && CarbonImmutable::parse($observed)->betweenIncluded($decision->decided_at->copy()->subMinutes(60), $decision->decided_at);
            } catch (\Throwable) {
                $validObservation = false;
            }
            if ($opposite && $validObservation
                && CfbStoredPregameQuote::contract($opposite) === CfbStoredPregameQuote::contract($entry)
                && $opposite->captured_at->betweenIncluded($decision->decided_at->copy()->subMinutes(60), $decision->decided_at) && abs((float) $opposite->line + (float) $decision->line) < .001
                && is_numeric($opposite->price) && abs((int) $opposite->price) >= 100) {
                $prices[$opposite->side] = (int) $opposite->price;
            }
        }

        return ['game_id' => $game->id, 'week' => $game->week, 'decision_id' => $decision->id,
            'release_version' => $prediction->model_version, 'snapshot_id' => $snapshot->id,
            'captured_at' => $snapshot->captured_at->toIso8601String(), 'decided_at' => $decision->decided_at->toIso8601String(),
            'market_home_line' => $homeLine, 'actual_home_margin' => $actualMargin,
            'models' => ['official' => $this->assess(-(float) $spread->projected_line, $homeLine, $actualMargin, $prices),
                'fpi_baseline' => $this->assess((float) $fpi, $homeLine, $actualMargin, $prices)]];
    }

    public function assess(float $modelHomeMargin, float $marketHomeLine, float $actualHomeMargin, array $prices): array
    {
        $edge = $modelHomeMargin + $marketHomeLine;
        $side = $edge >= 0 ? 'home' : 'away';
        $selected = abs($edge) >= 3;
        $resultMargin = $side === 'home' ? $actualHomeMargin + $marketHomeLine : -$actualHomeMargin - $marketHomeLine;
        $result = ! $selected ? 'no_signal' : ($resultMargin == 0 ? 'push' : ($resultMargin > 0 ? 'win' : 'loss'));
        $price = $prices[$side] ?? null;
        $priced = $selected && is_numeric($price) && abs((int) $price) >= 100;
        $profit = ! $priced ? null : ($result === 'push' ? 0.0 : ($result === 'loss' ? -1.0 : ($price > 0 ? $price / 100 : 100 / abs($price))));

        return ['home_margin' => $modelHomeMargin, 'margin_absolute_error' => abs($modelHomeMargin - $actualHomeMargin),
            'side' => $side, 'edge_points' => abs($edge), 'selected' => $selected, 'result' => $result,
            'price' => $price, 'shadow_profit_units' => $profit, 'qualified_bet' => false];
    }

    public function summarize(array $rows, string $model): array
    {
        $values = collect($rows)->map(fn ($r) => $r['models'][$model]);
        $selected = $values->where('selected', true);
        $priced = $selected->filter(fn ($r) => $r['shadow_profit_units'] !== null);

        return ['paired_games' => $values->count(), 'margin_mae' => $values->avg('margin_absolute_error'),
            'signals' => $selected->count(), 'wins' => $selected->where('result', 'win')->count(),
            'losses' => $selected->where('result', 'loss')->count(), 'pushes' => $selected->where('result', 'push')->count(),
            'priced_signals' => $priced->count(), 'shadow_profit_units' => $priced->isEmpty() ? null : $priced->sum('shadow_profit_units'),
            'shadow_roi' => $priced->isEmpty() ? null : $priced->sum('shadow_profit_units') / $priced->count()];
    }
}
