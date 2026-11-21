<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\PredictionReviewInput;

/** Pure interpretation ceiling; callers must recheck approval on publication. */
class PredictionReviewDecisionPolicy
{
    public function __construct(private readonly SportsAiPublishingDecisionPolicy $compatibility) {}

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>|null  $publishingGuardrail
     * @return array<string, mixed>
     */
    public function constrain(PredictionReviewInput $input, array $analysis, ?array $publishingGuardrail = null): array
    {
        $contract = $input->decisionContract;
        if (($input->identity['sport'] ?? null) === 'nfl') {
            // Preserve NFL normalization, watch/pass semantics and optional
            // guardrail enforcement rather than maintaining a second NFL policy.
            $decision = $this->compatibility->decide('nfl', ['decision_contract' => $contract], ['summary' => '', ...$analysis], $publishingGuardrail);

            return [
                'bet_classification' => $decision['bet_classification'],
                'recommendation' => $decision['recommendation'],
                'selection' => $decision['recommendation'] !== 'pass' ? ($contract['selection'] ?? null) : null,
                'authority' => $contract['authority'] ?? null,
            ];
        }

        $ranks = ['pass' => 0, 'watch' => 1, 'lean' => 2, 'bet' => 3];
        $ceiling = (string) ($contract['classification'] ?? 'pass');
        $requested = (string) ($analysis['bet_classification'] ?? 'pass');
        $classification = array_search(min($ranks[$ceiling] ?? 0, $ranks[$requested] ?? 0), $ranks, true);
        $market = (string) ($contract['recommendation'] ?? 'pass');
        $selection = $contract['selection'] ?? null;

        if (! in_array($analysis['recommendation'] ?? 'pass', ['moneyline', 'spread', 'total', 'prop', 'parlay_piece'], true)) {
            $classification = 'pass';
        }
        if (! is_array($selection) || ! in_array($market, ['moneyline', 'spread', 'total'], true)) {
            $classification = 'pass';
        }
        $playable = ($ranks[$classification] ?? 0) >= $ranks['lean'];

        return [
            'bet_classification' => $classification,
            'recommendation' => $playable ? $market : 'pass',
            // Never accept an AI-supplied side, line, price, or approval ID.
            'selection' => $playable ? $selection : null,
            'authority' => $contract['authority'] ?? null,
        ];
    }
}
