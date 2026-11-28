<?php

namespace App\Services\NFL;

class NflTotalRuleSupport
{
    /**
     * @return array{action:string,rules:array<int, array<string, mixed>>,label:string}|null
     */
    public function forPrediction(object $prediction, string $direction): ?array
    {
        $direction = strtolower($direction);
        if (! in_array($direction, ['over', 'under'], true)) {
            return null;
        }

        $metadata = is_array($prediction->model_metadata ?? null) ? $prediction->model_metadata : [];
        $analysis = $metadata['analysis_layer'] ?? [];
        if (! is_array($analysis)) {
            return null;
        }

        $reasonCodes = array_map('strval', (array) ($analysis['reason_codes'] ?? []));
        if (! in_array("market_total_edge_{$direction}", $reasonCodes, true)) {
            return null;
        }

        $matchedRules = collect((array) data_get($analysis, 'bet_rule_evaluation.matched_rules', []))
            ->filter(fn (mixed $rule): bool => is_array($rule) && ($rule['market'] ?? null) === 'total')
            ->filter(fn (array $rule): bool => in_array((string) ($rule['action'] ?? ''), ['play', 'lean'], true))
            ->filter(fn (array $rule): bool => $this->ruleMatchesDirection($rule, $direction))
            ->values();

        if ($matchedRules->isEmpty()) {
            return null;
        }

        $hasPlay = $matchedRules->contains(fn (array $rule): bool => ($rule['action'] ?? null) === 'play');
        $best = $matchedRules->first();

        return [
            'action' => $hasPlay ? 'play' : 'lean',
            'rules' => $matchedRules->all(),
            'label' => (string) ($best['label'] ?? $best['name'] ?? 'Total signal'),
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function ruleMatchesDirection(array $rule, string $direction): bool
    {
        // The global matching market edge admits neutral rules, but cannot
        // override an explicit direction in this rule's own name or label.
        $haystack = strtolower((string) ($rule['name'] ?? '').' '.(string) ($rule['label'] ?? ''));
        $opposite = $direction === 'over' ? 'under' : 'over';

        return preg_match('/(?:^|[^a-z0-9])'.$opposite.'(?:$|[^a-z0-9])/', $haystack) !== 1;
    }
}
