<?php

namespace App\Support;

class NflBetRuleEngine
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $riskFlags
     * @return array<string, mixed>
     */
    public function evaluate(array $reasonCodes, array $riskFlags, float $trustScore, ?float $spreadEdge, ?float $totalEdge): array
    {
        if (! (bool) config('nfl.predictions.bet_rules.enabled', true)) {
            return [
                'enabled' => false,
                'action' => 'disabled',
                'matched_rules' => [],
                'pass_rules' => [],
            ];
        }

        $matched = [];
        $passes = [];
        $tokens = array_values(array_unique(array_merge($reasonCodes, $riskFlags)));

        foreach ((array) config('nfl.predictions.bet_rules.rules', []) as $rule) {
            if (! is_array($rule) || ! $this->ruleMatches($rule, $tokens, $trustScore, $spreadEdge, $totalEdge)) {
                continue;
            }

            $name = (string) ($rule['name'] ?? 'unnamed_rule');
            if (($rule['action'] ?? null) === 'pass') {
                $passes[] = $name;
            } else {
                $matched[] = [
                    'name' => $name,
                    'action' => (string) ($rule['action'] ?? 'lean'),
                    'market' => $rule['market'] ?? null,
                ];
            }
        }

        $action = match (true) {
            $passes !== [] => 'pass',
            collect($matched)->contains(fn (array $rule): bool => $rule['action'] === 'play') => 'play',
            $matched !== [] => 'lean',
            default => 'none',
        };

        return [
            'enabled' => true,
            'action' => $action,
            'matched_rules' => $matched,
            'pass_rules' => $passes,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $tokens
     */
    protected function ruleMatches(array $rule, array $tokens, float $trustScore, ?float $spreadEdge, ?float $totalEdge): bool
    {
        if (isset($rule['min_trust']) && $trustScore < (float) $rule['min_trust']) {
            return false;
        }

        $market = $rule['market'] ?? null;
        if ($market === 'spread' && $spreadEdge === null) {
            return false;
        }
        if ($market === 'total' && $totalEdge === null) {
            return false;
        }

        foreach ((array) ($rule['require'] ?? []) as $required) {
            if (! in_array((string) $required, $tokens, true)) {
                return false;
            }
        }

        foreach ((array) ($rule['avoid'] ?? []) as $avoid) {
            if (in_array((string) $avoid, $tokens, true)) {
                return false;
            }
        }

        $requireAny = (array) ($rule['require_any'] ?? []);
        if ($requireAny !== [] && ! collect($requireAny)->contains(fn (mixed $token): bool => in_array((string) $token, $tokens, true))) {
            return false;
        }

        return true;
    }
}
