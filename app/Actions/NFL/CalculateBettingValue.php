<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractLineBasedCalculateBettingValue;
use App\Services\NFL\NflTotalRuleSupport;

class CalculateBettingValue extends AbstractLineBasedCalculateBettingValue
{
    protected function sportKey(): string
    {
        return 'nfl';
    }

    protected function getTeamDisplayName(object $team): string
    {
        $location = $team->location ?? '';
        $name = $team->name ?? '';

        return trim("{$location} {$name}") ?: $team->abbreviation ?? 'Unknown';
    }

    protected function analyzeTotal(object $prediction, array $market): ?array
    {
        $recommendation = parent::analyzeTotal($prediction, $market);
        if ($recommendation === null) {
            return null;
        }

        $direction = str_contains(strtolower((string) $recommendation['recommendation']), 'over') ? 'over' : 'under';
        $support = app(NflTotalRuleSupport::class)->forPrediction($prediction, $direction);
        if ($support === null) {
            return null;
        }

        return [
            ...$recommendation,
            'total_signal_action' => $support['action'],
            'total_signal_rules' => $support['rules'],
            'total_signal_direction' => $direction,
            'total_signal_label' => $support['label'],
        ];
    }

    protected function analyzeMoneyline(object $game, object $prediction, array $market): ?array
    {
        $recommendation = parent::analyzeMoneyline($game, $prediction, $market);
        if ($recommendation === null) {
            return null;
        }

        $trustScore = $this->analysisTrustScore($prediction);
        if ($trustScore < (float) config('nfl.betting.moneyline.min_trust', 65.0)) {
            return null;
        }

        $edge = (float) ($recommendation['edge'] ?? 0.0);
        $playEnabled = (bool) config('nfl.betting.moneyline.play_enabled', false);
        $playMinTrust = (float) config('nfl.betting.moneyline.play_min_trust', 85.0);
        $playMinEdge = (float) config('nfl.betting.moneyline.play_min_edge', 10.0);

        return [
            ...$recommendation,
            'trust_score' => round($trustScore, 1),
            'moneyline_signal_action' => $playEnabled && $trustScore >= $playMinTrust && $edge >= $playMinEdge
                ? 'play'
                : 'lean',
        ];
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<string, mixed>
     */
    protected function gradeRecommendation(array $recommendation, object $game, object $prediction): array
    {
        $graded = parent::gradeRecommendation($recommendation, $game, $prediction);

        if (($recommendation['type'] ?? null) === 'total' && ! $this->isPlayableTotalRecommendation($recommendation)) {
            return [
                ...$graded,
                'grade' => 'Watchlist',
                'risk_level' => 'watchlist',
                'bet_units' => 0.0,
                'recommendation_strength' => 'watchlist',
                'is_playable' => false,
            ];
        }

        if (($recommendation['type'] ?? null) === 'moneyline' && ($recommendation['moneyline_signal_action'] ?? null) !== 'play') {
            return [
                ...$graded,
                'grade' => 'Watchlist',
                'risk_level' => 'watchlist',
                'bet_units' => 0.0,
                'recommendation_strength' => 'watchlist',
                'is_playable' => false,
            ];
        }

        return $graded;
    }

    /**
     * @param  array<string, mixed>  $recommendation
     */
    private function isPlayableTotalRecommendation(array $recommendation): bool
    {
        $edge = (float) ($recommendation['edge'] ?? 0);
        $min = (float) config('nfl.betting.totals.play_min_edge', 4.0);
        $max = (float) config('nfl.betting.totals.play_max_edge', 6.0);
        $watchlistOnly = array_map('strval', (array) config('nfl.betting.totals.watchlist_only_rules', []));
        $rules = collect((array) ($recommendation['total_signal_rules'] ?? []))
            ->map(fn (mixed $rule): string => is_array($rule) ? (string) ($rule['name'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        if ($edge < $min || $edge >= $max) {
            return false;
        }

        return collect($rules)->intersect($watchlistOnly)->isEmpty();
    }

    private function analysisTrustScore(object $prediction): float
    {
        $metadata = is_array($prediction->model_metadata ?? null) ? $prediction->model_metadata : [];
        $trust = data_get($metadata, 'analysis_layer.trust_score');

        return is_numeric($trust) ? (float) $trust : (float) ($prediction->confidence_score ?? 0);
    }

    protected function teamMatchesOutcome(object $team, string $outcomeName, string $oddsApiTeamName): bool
    {
        $outcomeLower = strtolower($outcomeName);
        $oddsApiLower = strtolower($oddsApiTeamName);

        $outcomeLower = str_replace('los angeles', 'la', $outcomeLower);
        $oddsApiLower = str_replace('los angeles', 'la', $oddsApiLower);

        $teamLocation = strtolower($team->location ?? '');
        if (! empty($teamLocation) && (str_contains($outcomeLower, $teamLocation) || str_contains($oddsApiLower, $teamLocation))) {
            return true;
        }

        $teamName = strtolower($team->name ?? '');
        if (! empty($teamName) && str_contains($outcomeLower, $teamName)) {
            return true;
        }

        return $outcomeLower === $oddsApiLower;
    }
}
