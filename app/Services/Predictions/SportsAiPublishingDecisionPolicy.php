<?php

namespace App\Services\Predictions;

class SportsAiPublishingDecisionPolicy
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>|null  $publishingGuardrail
     * @return array{
     *   recommendation:string,
     *   bet_classification:string,
     *   summary:string,
     *   risk_flags:array<int,string>,
     *   reason_codes:array<int,string>,
     *   market_notes:array<string,string|null>,
     *   enforcement:array<string,mixed>
     * }
     */
    public function decide(
        string $sport,
        array $payload,
        array $analysis,
        ?array $publishingGuardrail,
    ): array {
        $guardrailDecision = $this->applyConfiguredGuardrail($analysis, $publishingGuardrail);

        if (strtolower($sport) !== 'nfl') {
            return [
                ...$guardrailDecision,
                'summary' => (string) $analysis['summary'],
                'risk_flags' => array_values((array) ($analysis['risk_flags'] ?? [])),
                'reason_codes' => array_values((array) ($analysis['reason_codes'] ?? [])),
                'market_notes' => (array) ($analysis['market_notes'] ?? []),
            ];
        }

        $contract = (array) ($payload['decision_contract'] ?? []);
        $contractClassification = $this->classification((string) ($contract['classification'] ?? 'pass'));
        $contractRecommendation = $this->recommendation((string) ($contract['recommendation'] ?? 'pass'));
        $guardrailClassification = $this->classification($guardrailDecision['bet_classification']);
        $originalRecommendation = $this->recommendation((string) ($analysis['recommendation'] ?? 'pass'));
        $reviewedRecommendation = $this->recommendation($guardrailDecision['recommendation']);
        $finalClassification = $this->lowerClassification($contractClassification, $guardrailClassification);
        $reasons = [];

        if ($contract === []) {
            $finalClassification = 'pass';
            $contractRecommendation = 'pass';
            $reasons[] = 'missing_deterministic_decision_contract';
        }

        if ($reviewedRecommendation === 'pass') {
            $finalClassification = 'pass';
            $reasons[] = $originalRecommendation === 'pass'
                ? 'ai_downgrade_to_pass'
                : 'publishing_guardrail_hold';
        }

        if ($this->classificationRank($finalClassification) < $this->classificationRank('lean')) {
            $finalRecommendation = 'pass';
        } else {
            $finalRecommendation = $contractRecommendation;
        }

        if ($contractRecommendation === 'pass') {
            $finalRecommendation = 'pass';
            $finalClassification = $this->lowerClassification($finalClassification, 'watch');
            $reasons[] = 'no_eligible_deterministic_market';
        } elseif (! in_array($originalRecommendation, [$contractRecommendation, 'pass'], true)) {
            $reasons[] = 'ai_market_overridden_by_deterministic_contract';
        }

        if ($this->classificationRank($guardrailClassification) > $this->classificationRank($contractClassification)) {
            $reasons[] = 'ai_classification_capped_by_deterministic_contract';
        }

        $riskFlags = array_values(array_unique(array_filter([
            ...(array) ($contract['risk_flags'] ?? []),
            ...(array) ($analysis['risk_flags'] ?? []),
            ...$reasons,
        ], 'is_string')));
        $reasonCodes = array_values(array_unique(array_filter(
            (array) ($contract['reason_codes'] ?? []),
            'is_string',
        )));

        return [
            'recommendation' => $finalRecommendation,
            'bet_classification' => $finalClassification,
            'summary' => $this->nflSummary($finalClassification, $finalRecommendation, $contract),
            'risk_flags' => $riskFlags,
            'reason_codes' => $reasonCodes,
            'market_notes' => $this->nflMarketNotes($finalClassification, $finalRecommendation, $contract),
            'enforcement' => [
                ...$guardrailDecision['enforcement'],
                'deterministic_contract_applied' => true,
                'deterministic_contract_version' => $contract['schema_version'] ?? null,
                'contract_classification' => $contractClassification,
                'contract_recommendation' => $contractRecommendation,
                'generated_recommendation' => $originalRecommendation,
                'generated_bet_classification' => $this->classification((string) ($analysis['bet_classification'] ?? 'pass')),
                'effective_recommendation' => $finalRecommendation,
                'effective_bet_classification' => $finalClassification,
                'reasons' => $reasons,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>|null  $publishingGuardrail
     * @return array{recommendation:string,bet_classification:string,enforcement:array<string,mixed>}
     */
    private function applyConfiguredGuardrail(array $analysis, ?array $publishingGuardrail): array
    {
        $originalRecommendation = $this->recommendation((string) ($analysis['recommendation'] ?? 'pass'));
        $originalClassification = $this->classification((string) ($analysis['bet_classification'] ?? 'pass'));
        $enforced = (bool) config('ai.features.publishing_guardrail_review.enforced', false);
        $decision = (string) data_get($publishingGuardrail, 'decision', 'shadow');
        $reviewClassification = $this->classification((string) data_get($publishingGuardrail, 'publishable_classification', 'pass'));
        $recommendation = $originalRecommendation;
        $classification = $originalClassification;

        if ($enforced && $publishingGuardrail) {
            if (in_array($decision, ['downgrade', 'hold', 'block'], true)) {
                $classification = $this->lowerClassification($classification, $reviewClassification);
            }

            if (in_array($decision, ['hold', 'block'], true)) {
                $recommendation = 'pass';
            }
        }

        return [
            'recommendation' => $recommendation,
            'bet_classification' => $classification,
            'enforcement' => [
                'enabled' => $enforced,
                'applied' => $enforced && $publishingGuardrail !== null && (
                    $recommendation !== $originalRecommendation || $classification !== $originalClassification
                ),
                'decision' => $decision,
                'original_recommendation' => $originalRecommendation,
                'original_bet_classification' => $originalClassification,
                'effective_recommendation' => $recommendation,
                'effective_bet_classification' => $classification,
            ],
        ];
    }

    /** @param array<string, mixed> $contract */
    private function nflSummary(string $classification, string $recommendation, array $contract): string
    {
        $selection = (array) ($contract['selection'] ?? []);

        if ($recommendation === 'pass' || $this->classificationRank($classification) < $this->classificationRank('lean')) {
            return 'No NFL wager is approved by the deterministic decision policy. AI context remains available for research, but it cannot upgrade this '.$classification.' result.';
        }

        $edge = is_numeric($selection['edge'] ?? null) ? abs((float) $selection['edge']).' points of model edge' : 'a qualifying model edge';
        $pick = match ($recommendation) {
            'spread' => trim((string) ($selection['team'] ?? 'Selected side').' '.$this->formatLine($selection['line'] ?? null)),
            'total' => strtoupper((string) ($selection['direction'] ?? '')).' '.($selection['line'] ?? ''),
            'moneyline' => trim((string) ($selection['team'] ?? 'Selected side').' moneyline'),
            default => $recommendation,
        };

        return 'The deterministic NFL decision policy rates '.$pick.' as a '.$classification.' with '.$edge.'. AI context may explain or downgrade this decision, but cannot change its market or strengthen its tier.';
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{moneyline:string|null,spread:string|null,total:string|null,props:string|null}
     */
    private function nflMarketNotes(string $classification, string $recommendation, array $contract): array
    {
        $notes = [
            'moneyline' => null,
            'spread' => null,
            'total' => null,
            'props' => null,
        ];

        if ($recommendation === 'pass' || $this->classificationRank($classification) < $this->classificationRank('lean')) {
            return $notes;
        }

        $selection = (array) ($contract['selection'] ?? []);
        $notes[$recommendation] = match ($recommendation) {
            'spread' => trim((string) ($selection['team'] ?? 'Selected side').' '.$this->formatLine($selection['line'] ?? null)).' is the only approved market.',
            'total' => strtoupper((string) ($selection['direction'] ?? '')).' '.($selection['line'] ?? '').' is the only approved market.',
            'moneyline' => trim((string) ($selection['team'] ?? 'Selected side').' moneyline').' is the only approved market.',
            default => null,
        };

        return $notes;
    }

    private function formatLine(mixed $line): string
    {
        if (! is_numeric($line)) {
            return '';
        }

        $line = (float) $line;

        return $line > 0 ? '+'.$line : (string) $line;
    }

    private function recommendation(string $recommendation): string
    {
        $recommendation = strtolower(trim(str_replace(' ', '_', $recommendation)));

        return in_array($recommendation, ['moneyline', 'spread', 'total', 'prop', 'parlay_piece', 'pass'], true)
            ? $recommendation
            : 'pass';
    }

    private function classification(string $classification): string
    {
        $classification = strtolower(trim(str_replace(' ', '_', $classification)));

        return match (true) {
            in_array($classification, ['bet', 'official_bet', 'official_candidate'], true) => 'bet',
            in_array($classification, ['lean', 'model_lean'], true) => 'lean',
            in_array($classification, ['watch', 'watchlist'], true) || str_contains($classification, 'watchlist') => 'watch',
            default => 'pass',
        };
    }

    private function lowerClassification(string $first, string $second): string
    {
        return $this->classificationRank($first) <= $this->classificationRank($second) ? $first : $second;
    }

    private function classificationRank(string $classification): int
    {
        return match ($classification) {
            'bet' => 3,
            'lean' => 2,
            'watch' => 1,
            default => 0,
        };
    }
}
