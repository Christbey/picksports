<?php

namespace App\Services\CFB;

use App\Models\CFB\PlayerProp;
use App\Services\BettingRecommendations\CfbPropEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Append-only pregame evidence. This never alters recommendation scores. */
class CfbPropScoringShadowRecorder
{
    public function record(PlayerProp $prop, array $analysis): void
    {
        if (! Schema::hasTable('cfb_prop_signal_snapshots') || ! CfbPropEligibility::eligible($prop)
            || $prop->fetched_at->isFuture()) {
            return;
        }
        $kickoff = CfbPropEligibility::kickoff($prop->game);
        $payload = ['policy' => 'cfb-prop-scoring-shadow-v1', 'analysis' => $analysis,
            'candidate' => $this->candidate($analysis),
            'quote_fetched_at' => $prop->fetched_at->toIso8601String(),
            'quote_source_updated_at' => data_get($prop->raw_data, 'last_update'),
            'scoring_policy_sha256' => hash_file('sha256', app_path('Services/CFB/CfbRelativeVariabilityPenalty.php')),
            'calculator_sha256' => hash_file('sha256', app_path('Services/BettingRecommendations/PlayerPropAnalyzer.php')),
            'probability_status' => 'uncalibrated', 'recommendations_approved' => false];
        $identity = [$prop->id, $prop->game_id, $prop->player_id, $prop->market, $prop->bookmaker,
            $prop->line, $prop->over_price, $prop->under_price, $kickoff->toIso8601String(), $payload];
        DB::table('cfb_prop_signal_snapshots')->insertOrIgnore([
            'prop_id' => $prop->id, 'game_id' => $prop->game_id, 'player_id' => $prop->player_id,
            'market' => $prop->market, 'bookmaker' => $prop->bookmaker ?? 'unknown',
            'line' => $prop->line, 'over_price' => $prop->over_price, 'under_price' => $prop->under_price,
            'observed_at' => now()->utc(), 'kickoff_at' => $kickoff->utc(),
            'fingerprint' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /** Experimental penalty-only comparison; never changes the production signal. */
    public function candidate(array $analysis): array
    {
        if (data_get($analysis, 'confidence_decomposition.cfb_scoring_policy.applied')) {
            return ['policy' => CfbRelativeVariabilityPenalty::VERSION, 'status' => 'active_rule',
                'candidate_score' => $analysis['confidence'] ?? null, 'approved' => false];
        }
        $std = data_get($analysis, 'confidence_decomposition.stat_summary.consistency.std_dev');
        $mean = data_get($analysis, 'confidence_decomposition.stat_summary.season_avg');
        $games = (int) data_get($analysis, 'confidence_decomposition.cfb_context.evidence.windows.this_season.games', 0);
        $oldUncertainty = data_get($analysis, 'confidence_decomposition.uncertainty_penalty');
        $cap = data_get($analysis, 'confidence_decomposition.confidence_cap');
        $score = $analysis['confidence'] ?? null;
        $adjustment = data_get($analysis, 'confidence_decomposition.cover_record_adjustment', []);
        if (! is_numeric($std) || ! is_numeric($mean) || $mean <= 0 || ! is_numeric($oldUncertainty)
            || ! is_numeric($cap) || ! is_numeric($score) || $score <= 32 || $score >= $cap
            || (isset($adjustment['original_confidence']) && $adjustment['original_confidence'] >= $cap)) {
            return ['policy' => 'relative-variability-penalty-v1', 'status' => 'not_comparable', 'approved' => false];
        }
        $legacyVolatility = $std >= 7 ? 10 : ($std >= 5.5 ? 4 : ($std <= 3 ? -4 : 0));
        $relative = (float) $std / (float) $mean;
        $penalty = min(14, (int) round(min(8, $relative * 8)) + max(0, 6 - $games));

        return ['policy' => 'relative-variability-penalty-v1', 'status' => 'shadow_only', 'approved' => false,
            'relative_stddev' => round($relative, 4), 'legacy_penalty' => $legacyVolatility + $oldUncertainty,
            'candidate_penalty' => $penalty,
            'candidate_score' => max(32, min((int) $cap, (int) $score + $legacyVolatility + (int) $oldUncertainty - $penalty)),
            'scope' => 'penalty-only sensitivity; original probabilities, prices, caps, side and hold rules retained'];
    }
}
