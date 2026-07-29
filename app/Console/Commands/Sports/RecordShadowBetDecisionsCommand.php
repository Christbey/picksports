<?php

namespace App\Console\Commands\Sports;

use App\Models\BetDecision;
use App\Models\MarketQuote;
use App\Models\ShadowModelOutput;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RecordShadowBetDecisionsCommand extends Command
{
    protected $signature = 'sports:record-shadow-bet-decisions
        {--sport=nba : Sport to process}
        {--artifact= : Restrict to one artifact UUID}
        {--minimum-edge=0.03 : Edge required after promotion}
        {--limit=0 : Optional output limit}';

    protected $description = 'Write challenger or promoted shadow outputs into immutable tracking decisions';

    public function handle(): int
    {
        $sport = strtolower((string) $this->option('sport'));
        $query = ShadowModelOutput::query()
            ->with(['artifact', 'featureSnapshot'])
            ->where('sport', $sport)
            ->when($this->option('artifact'), fn ($builder) => $builder->where('model_artifact_id', $this->option('artifact')))
            ->orderBy('id');

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $created = 0;
        foreach ($query->get() as $shadow) {
            $snapshot = $shadow->featureSnapshot;
            $artifact = $shadow->artifact;
            if (! $snapshot || ! $artifact) {
                continue;
            }

            $homeProbability = (float) $shadow->challenger_output;
            $side = $homeProbability >= 0.5 ? 'home' : 'away';
            $sideProbability = $side === 'home' ? $homeProbability : 1.0 - $homeProbability;
            $quote = MarketQuote::query()
                ->where('sport', $sport)
                ->where('game_id', $shadow->game_id)
                ->where('market_key', 'h2h')
                ->where('side', $side)
                ->where('is_pregame', true)
                ->where('captured_at', '<=', $shadow->generated_at)
                ->when($snapshot->game_start_at, fn ($builder) => $builder->where('captured_at', '<=', $snapshot->game_start_at))
                ->latest('captured_at')
                ->first();
            $marketProbability = is_numeric($quote?->no_vig_probability)
                ? (float) $quote->no_vig_probability
                : null;
            $edge = $marketProbability === null ? null : $sideProbability - $marketProbability;
            $promotedAtDecisionTime = $artifact->status === 'promoted'
                && $artifact->promoted_at !== null
                && $artifact->promoted_at->lessThanOrEqualTo($shadow->generated_at);
            $minimumEdge = max(0.0, (float) $this->option('minimum-edge'));
            $pregameSafe = (bool) $snapshot->pregame_safe && (bool) $quote?->is_pregame;
            $isBet = $promotedAtDecisionTime && $pregameSafe && $edge !== null && $edge >= $minimumEdge;
            $eligibilityReasons = array_values(array_filter([
                $promotedAtDecisionTime ? null : 'artifact_not_promoted_at_decision_time',
                $snapshot->pregame_safe ? null : 'feature_snapshot_not_pregame_safe',
                $quote ? null : 'pregame_market_quote_missing',
                $quote && ! $quote->is_pregame ? 'market_quote_not_pregame' : null,
                $edge !== null && $edge < $minimumEdge ? 'edge_below_threshold' : null,
            ]));
            $decisionHash = hash('sha256', implode('|', [
                $artifact->id,
                $shadow->id,
                $quote?->id,
                $side,
                $isBet ? 'bet' : 'no-bet',
            ]));

            $decision = BetDecision::query()->firstOrCreate(
                ['decision_hash' => $decisionHash],
                [
                    'decision_run_id' => (string) Str::uuid(),
                    'model_run_id' => $shadow->inference_run_id,
                    'model_artifact_id' => $artifact->id,
                    'shadow_model_output_id' => $shadow->id,
                    'prediction_feature_snapshot_id' => $snapshot->id,
                    'game_odds_snapshot_id' => $quote?->game_odds_snapshot_id,
                    'source_table' => 'shadow_model_outputs',
                    'source_id' => $shadow->id,
                    'sport' => $sport,
                    'game_table' => $shadow->game_table,
                    'game_id' => $shadow->game_id,
                    'prediction_table' => $shadow->prediction_table,
                    'prediction_id' => $shadow->prediction_id,
                    'market_type' => 'moneyline',
                    'market_key' => 'h2h',
                    'side' => $side,
                    'price' => $quote?->price,
                    'bookmaker' => $quote?->bookmaker_key,
                    'market_probability' => $quote?->implied_probability,
                    'no_vig_probability' => $quote?->no_vig_probability,
                    'model_probability' => $sideProbability,
                    'blend_probability' => $sideProbability,
                    'edge' => $edge,
                    'projected_value' => $edge,
                    'confidence' => abs($sideProbability - 0.5) * 2,
                    'status' => $isBet ? 'tracking_bet' : 'shadow_no_bet',
                    'recommendation_label' => $isBet ? 'shadow_bet' : 'no_bet',
                    'is_public' => false,
                    'is_tracking_only' => true,
                    'is_bet' => $isBet,
                    'pregame_safe' => $pregameSafe,
                    'eligibility_reasons' => $eligibilityReasons,
                    'risk_flags' => [],
                    'reason_codes' => $isBet ? ['promoted_model_edge'] : ['shadow_model_observation'],
                    'explanation' => [
                        'decision' => $isBet ? 'bet' : 'no_bet',
                        'artifact_status' => $artifact->status,
                        'artifact_promoted_at' => $artifact->promoted_at?->toIso8601String(),
                        'promoted_at_decision_time' => $promotedAtDecisionTime,
                        'model_probability' => $sideProbability,
                        'market_probability' => $marketProbability,
                        'edge' => $edge,
                        'minimum_edge' => $minimumEdge,
                        'why_not_bet' => $eligibilityReasons,
                    ],
                    'feature_snapshot' => [
                        'snapshot_run_id' => $snapshot->snapshot_run_id,
                        'feature_hash' => $snapshot->feature_hash,
                        'features_available_at' => $snapshot->features_available_at?->toIso8601String(),
                    ],
                    'market_snapshot' => $quote?->toArray(),
                    'decided_at' => $shadow->generated_at,
                    'locked_at' => $shadow->generated_at,
                    'game_start_at' => $snapshot->game_start_at,
                ],
            );

            $created += $decision->wasRecentlyCreated ? 1 : 0;
        }

        $this->info("Recorded {$created} new immutable shadow decision(s).");

        return self::SUCCESS;
    }
}
