<?php

namespace App\Services\NFL\Research;

class RecommendationEligibility
{
    public function evaluate(array $analysis, array $metadata, array $holds = []): array
    {
        $modelReasons = [];
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
