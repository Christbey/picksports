<?php

namespace App\Services\NFL\Research;

class RecommendationEligibility
{
    /**
     * Report data completeness and model consensus separately. A candidate here
     * is not release approval: immutable pregame evidence and quotes are checked
     * by NflReleasedBetDecisionRecorder. Data holds take precedence over a pass.
     */
    public function evaluate(array $analysis, array $metadata, array $holds = []): array
    {
        $modelReasons = [];
        if (data_get($metadata, 'true_epa.enabled') === true
            && data_get($metadata, 'true_epa.applied') !== true) {
            $holds[] = 'missing_true_epa';
        }
        if (($analysis['raw_bet_classification'] ?? $analysis['bet_classification'] ?? null) !== 'bet') {
            $modelReasons[] = 'general_model_does_not_approve';
        }
        foreach (['tier', 'market_scores.spread.tier'] as $path) {
            if (! in_array(data_get($analysis, 'pro_signal_layer.'.$path), ['official_candidate', 'lean'], true)) {
                $modelReasons[] = 'specialist_'.str_replace('.', '_', $path).'_does_not_approve';
            }
        }
        if (data_get($metadata, 'qb_form.reason') === 'insufficient_prior_attempts') {
            $holds[] = 'missing_quarterback_history';
        }
        if (data_get($metadata, 'qb_form.enabled') === true && data_get($metadata, 'qb_form.reason') === 'missing_game_qb_identity') {
            $holds[] = 'missing_quarterback_identity';
        }

        return [
            'eligible' => $holds === [] && $modelReasons === [],
            'status' => $holds !== [] ? 'hold' : ($modelReasons !== [] ? 'pass' : 'candidate'),
            'data_complete' => $holds === [],
            'model_approved' => $modelReasons === [],
            'data_reasons' => array_values(array_unique($holds)),
            'model_reasons' => $modelReasons,
            'reasons' => array_values(array_unique([...$holds, ...$modelReasons])),
        ];
    }
}
