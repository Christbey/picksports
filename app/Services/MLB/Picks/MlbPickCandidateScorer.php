<?php

namespace App\Services\MLB\Picks;

use App\Services\MLB\Signals\MlbSignalDriverService;

class MlbPickCandidateScorer
{
    public function __construct(
        private readonly MlbSignalDriverService $signals,
    ) {}

    /**
     * @return array{score:int,confidence:float,internal_label:string,reason_codes:list<string>,risk_flags:list<string>,feature_snapshot:array<string,mixed>}
     */
    public function score(MlbPickCandidateData $candidate): array
    {
        $score = 50;
        $signalLayer = $this->signals->forCandidate($candidate);
        $reasonCodes = array_values(array_unique((array) ($signalLayer['reason_codes'] ?? $candidate->reasonCodes)));
        $riskFlags = array_values(array_unique((array) ($signalLayer['risk_flags'] ?? $candidate->riskFlags)));

        if (in_array('model_market_agrees', $reasonCodes, true)) {
            $score += 12;
        }
        if (in_array('model_market_disagrees', $reasonCodes, true)) {
            $score -= 18;
            $riskFlags[] = 'model_market_disagreement';
        }

        $edge = $candidate->edgeNoVig ?? $candidate->edgeRaw;
        if ($edge !== null) {
            if ($edge >= 0.05) {
                $score += 25;
                $reasonCodes[] = 'positive_no_vig_edge';
            } elseif ($edge >= 0.035) {
                $score += 18;
                $reasonCodes[] = 'positive_no_vig_edge';
            } elseif ($edge >= 0.02) {
                $score += 10;
                $reasonCodes[] = 'positive_no_vig_edge';
            } elseif ($edge <= 0.0) {
                $score -= 25;
                $riskFlags[] = 'nonpositive_no_vig_edge';
            } else {
                $score -= 12;
                $riskFlags[] = 'edge_below_tracking_threshold';
            }
        } else {
            $score -= 15;
            $riskFlags[] = 'market_edge_missing';
        }

        if (($candidate->blendProbability ?? 0.0) >= 0.58) {
            $score += 18;
            $reasonCodes[] = 'blend_probability_strong';
        } elseif (($candidate->blendProbability ?? 0.0) >= 0.55) {
            $score += 12;
            $reasonCodes[] = 'blend_probability_supports_side';
        } elseif ($candidate->blendProbability !== null && $candidate->marketProbability !== null && $candidate->blendProbability > $candidate->marketProbability) {
            $score += 8;
            $reasonCodes[] = 'blend_supports_side';
        }

        $projectedValue = abs((float) ($candidate->projectedValue ?? 0.0));
        if (str_contains($candidate->marketType, 'total')) {
            if ($projectedValue >= 1.5) {
                $score += 12;
            } elseif ($projectedValue >= 1.0) {
                $score += 8;
            }
        }

        foreach ($reasonCodes as $code) {
            $score += match ($code) {
                'probable_pitchers_confirmed', 'starter_confirmed', 'f3_pitcher_edge', 'f5_pitcher_edge', 'pitcher_margin_support', 'player_recent_form_support' => 8,
                'bullpen_advantage', 'bullpen_margin_support', 'weather_supports_over', 'weather_supports_under', 'park_support' => 6,
                'line_value', 'matchup_support', 'season_rate_support' => 7,
                default => 0,
            };
        }

        foreach ($riskFlags as $flag) {
            $score -= match ($flag) {
                'stale_odds', 'stale_price', 'prop_market_stale' => 10,
                'missing_no_vig', 'unconfirmed_pitcher', 'weather_missing', 'roof_unknown', 'lineup_not_confirmed' => 10,
                'pitcher_changed', 'point_in_time_unsafe', 'live_only_or_postgame_unsafe' => 25,
                'high_variance_run_line', 'one_run_game_risk', 'f3_high_variance', 'low_sample_first_3' => 8,
                'low_model_confidence', 'away_pick_risk', 'low_sample_prop', 'pitch_count_risk' => 6,
                default => 0,
            };
        }

        $score += (int) ($signalLayer['score_delta'] ?? 0);

        if (! (bool) ($signalLayer['pregame_safe'] ?? true)) {
            $score = min($score, 57);
            $riskFlags[] = 'pregame_signal_safety_block';
        }
        if (in_array('nonpositive_no_vig_edge', $riskFlags, true) || in_array('market_edge_missing', $riskFlags, true)) {
            $score = min($score, 57);
        } elseif (in_array('edge_below_tracking_threshold', $riskFlags, true)) {
            $score = min($score, 67);
        }

        $score = max(0, min(100, $score));
        $label = match (true) {
            $score >= 80 => 'bet_candidate',
            $score >= 68 => 'lean_candidate',
            $score >= 58 => 'watch',
            default => 'no_play',
        };

        $featureSnapshot = $candidate->featureSnapshot;
        $featureSnapshot['internal_candidate_label'] = $label;
        $featureSnapshot['signal_layer'] = $signalLayer;

        return [
            'score' => $score,
            'confidence' => round($score / 100, 4),
            'internal_label' => $label,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'risk_flags' => array_values(array_unique($riskFlags)),
            'feature_snapshot' => $featureSnapshot,
        ];
    }
}
