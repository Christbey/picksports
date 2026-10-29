<?php

namespace App\Services\NFL\Predictions;

use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Services\NFL\NflCandidateDecisionPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NflCanonicalDecisionRecorder
{
    public function __construct(private readonly NflCandidateDecisionPolicy $policy) {}

    /** @return Collection<int, BetDecision> */
    public function record(CanonicalPrediction $prediction, Game $game): Collection
    {
        return DB::transaction(function () use ($prediction, $game): Collection {
            $prediction = CanonicalPrediction::query()->whereKey($prediction->getKey())
                ->where('publication_state', 'published')->lockForUpdate()->first();
            if ($prediction === null) {
                return collect();
            }
            $game = Game::query()->with('sportEvent')->find($game->getKey());
            if ($game === null) {
                return collect();
            }
            $prediction->loadMissing('calculationRun.inputSnapshot', 'calculationRun.release');
            $snapshot = $prediction->calculationRun?->inputSnapshot;
            $context = (array) data_get($snapshot?->inputs, 'recommendation_context', []);
            $now = now()->toImmutable();
            $start = $game->sportEvent?->starts_at;
            if ($prediction->sport !== 'nfl' || $prediction->phase !== 'pregame'
                || $prediction->publication_state !== 'published'
                || $prediction->sport_event_id !== $game->sport_event_id
                || $prediction->published_at === null || $prediction->published_at->gt($now)
                || $prediction->calculationRun?->status !== 'succeeded'
                || ! in_array($prediction->calculationRun?->release?->status, ['approved', 'retired'], true)
                || $snapshot?->pregame_safety_status !== 'verified'
                || $snapshot->sport_event_id !== $game->sport_event_id
                || $snapshot->captured_at === null || $snapshot->captured_at->gt($now)
                || $start === null || ! $now->lt($start) || ! $snapshot->captured_at->lt($start)
                || ! in_array((string) $game->season_type, NflPregameHorizon::seasonTypes(), true)
                || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
                || ($context['version'] ?? null) !== NflRecommendationInputBuilder::VERSION) {
                return collect();
            }
            $metadata = (array) ($context['model_metadata'] ?? []);
            $analysis = (array) ($metadata['analysis_layer'] ?? []);
            $outputs = (array) ($context['outputs'] ?? []);
            $configuration = (array) ($context['configuration'] ?? []);
            $candidates = $this->policy->candidateRecommendations($analysis, $configuration);
            $market = (array) data_get($snapshot->inputs, 'pregame_market', []);
            $decisions = collect();
            foreach (['moneyline' => 'h2h', 'spread' => 'spreads', 'total' => 'totals'] as $type => $key) {
                $candidate = $candidates->get($key);
                $forecast = $outputs[match ($type) {
                    'moneyline' => 'win_probability', 'spread' => 'predicted_spread', 'total' => 'predicted_total',
                }] ?? null;
                $reference = (array) data_get($market, 'consensus.'.$type, []);
                $line = is_numeric($reference['line'] ?? null) ? (float) $reference['line'] : null;
                $side = ! is_numeric($forecast) ? 'unknown' : match ($type) {
                    'moneyline' => (float) $forecast >= 0.5 ? 'home' : 'away',
                    'spread' => $line === null ? 'unknown' : ((float) $forecast + $line >= 0 ? 'home' : 'away'),
                    'total' => $line === null ? 'unknown' : ((float) $forecast >= $line ? 'over' : 'under'),
                };
                $selection = $candidate !== null
                    ? $this->policy->selection(is_numeric($outputs['win_probability'] ?? null) ? (float) $outputs['win_probability'] : null, $analysis, $candidate, $configuration)
                    : ['market_type' => $type, 'market_key' => $key, 'side' => $side,
                        'line' => $type === 'moneyline' ? null : ($type === 'spread' && $side === 'away' && $line !== null ? -$line : $line),
                        'model_probability' => $type === 'moneyline' && is_numeric($forecast) ? ($side === 'home' ? (float) $forecast : 1 - (float) $forecast) : null,
                        'edge' => null];
                $quote = $selection !== null && $selection['side'] !== 'unknown'
                    ? $this->policy->entryQuote($game, $selection, $now, $start->toImmutable()) : null;
                $holds = (array) data_get($analysis, 'eligibility.data_reasons', []);
                if (($analysis['applied'] ?? false) !== true) {
                    $holds[] = 'analysis_not_applied';
                }
                if ($candidate !== null && $selection === null) {
                    $holds[] = 'unsupported_candidate_selection';
                } elseif ($quote === null) {
                    $holds[] = $candidate !== null ? 'exact_fresh_paired_quote_missing' : 'fresh_paired_market_or_forecast_missing';
                }
                $holds = array_values(array_unique($holds));
                $released = $candidate !== null && $holds === [];
                $status = $candidate !== null
                    ? ($released ? 'released_tracking_bet' : 'held_candidate')
                    : ($holds === [] ? 'model_no_bet' : 'model_hold');
                $hash = hash('sha256', implode('|', ['nfl-canonical-disposition', $prediction->id, $snapshot->id, $key,
                    $status, $released ? 'locked' : ($quote?->id ?? 'missing'), implode(',', $holds)]));
                $decisions->push(BetDecision::query()->firstOrCreate(['decision_hash' => $hash], [
                    'decision_run_id' => (string) Str::uuid(),
                    'source_table' => 'predictions', 'source_id' => $prediction->id,
                    'sport' => 'nfl', 'game_table' => $game->getTable(), 'game_id' => $game->id,
                    'prediction_table' => 'predictions', 'prediction_id' => $prediction->id,
                    'prediction_feature_snapshot_id' => null,
                    'game_odds_snapshot_id' => $quote?->game_odds_snapshot_id,
                    'market_type' => $type, 'market_key' => $key, 'side' => $selection['side'] ?? 'unknown',
                    'line' => $quote?->line ?? ($selection['line'] ?? null), 'price' => $quote?->price,
                    'bookmaker' => $quote?->bookmaker_key ?? $quote?->bookmaker_title,
                    'model_probability' => $selection['model_probability'] ?? null,
                    'blend_probability' => $selection['model_probability'] ?? null,
                    'confidence' => $type === 'moneyline' && is_numeric($outputs['confidence_score'] ?? null) ? (float) $outputs['confidence_score'] / 100 : null,
                    'market_probability' => $quote?->implied_probability, 'no_vig_probability' => $quote?->no_vig_probability,
                    'edge' => $selection['edge'] ?? null, 'score' => $candidate['score'] ?? null,
                    'status' => $status, 'recommendation_label' => $released ? 'model_bet' : 'no_bet',
                    'is_public' => false, 'is_tracking_only' => true, 'is_bet' => $released,
                    'pregame_safe' => true,
                    'eligibility_reasons' => array_values(array_unique([...$holds, ...(array) data_get($analysis, 'eligibility.model_reasons', []), ...($candidate === null ? ['official_candidate_not_released'] : [])])),
                    'risk_flags' => (array) ($analysis['risk_flags'] ?? []), 'reason_codes' => (array) ($analysis['reason_codes'] ?? []),
                    'explanation' => ['authority' => 'nfl_deterministic_analysis_and_pro_signal_consensus',
                        'decision' => $released ? 'bet' : ($holds !== [] ? 'hold' : 'pass'),
                        'execution_status' => 'not_recorded', 'hold_reason' => $holds[0] ?? null, 'hold_reasons' => $holds,
                        'selection' => $selection, 'recommendation' => $candidate, 'forecast_value' => $forecast],
                    'feature_snapshot' => ['canonical_prediction_public_id' => $prediction->public_id,
                        'calculation_run_id' => $prediction->calculation_run_id, 'event_input_snapshot_id' => $snapshot->id,
                        'input_hash' => $snapshot->content_hash, 'recommendation_version' => $context['version'],
                        'recommendation_model_version' => $context['model_version'] ?? null],
                    'market_snapshot' => ['immutable_entry_quote' => true, ...($quote?->toArray() ?? [])],
                    'decided_at' => $now, 'locked_at' => $now, 'game_start_at' => $start,
                ]));
            }

            return $decisions;
        });
    }
}
