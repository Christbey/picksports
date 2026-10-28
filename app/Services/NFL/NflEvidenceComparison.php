<?php

namespace App\Services\NFL;

/** Descriptive matched-stratum comparisons; never a causal estimate or approval. */
class NflEvidenceComparison
{
    public function compare(array $present, array $absent): array
    {
        $a = collect($present)->filter(fn ($r) => in_array($r['result'], ['win', 'loss', 'push', 'no_pick'], true))->groupBy('stratum');
        $b = collect($absent)->filter(fn ($r) => in_array($r['result'], ['win', 'loss', 'push', 'no_pick'], true))->groupBy('stratum');
        $weight = 0;
        $difference = 0.;
        $controls = $exposed = $strata = 0;
        foreach ($a as $key => $rows) {
            $control = $b->get($key, collect());
            $treated = $rows->filter(fn ($r) => is_numeric($r['comparison_loss']));
            $control = $control->filter(fn ($r) => is_numeric($r['comparison_loss']));
            if ($treated->isEmpty() || $control->isEmpty()) {
                continue;
            }
            $w = min($treated->count(), $control->count());
            $difference += $w * ($control->avg('comparison_loss') - $treated->avg('comparison_loss'));
            $weight += $w;
            $controls += $control->count();
            $exposed += $treated->count();
            $strata++;
        }

        return ['present_games' => $exposed, 'control_games' => $controls, 'common_strata' => $strata,
            'balanced_weight' => $weight, 'comparison_improvement' => $weight ? $difference / $weight : null,
            'status' => $weight ? 'descriptive_association' : 'no_comparable_controls', 'validated' => false,
            'method' => 'Exact coarse strata: market favorite/line/total buckets, QB identity status, cohort and season stage. Positive means lower model loss with condition present; not an isolated signal effect.'];
    }

    public function stratum(array $context, array $market, ?int $week): string
    {
        $margin = $market['market_home_margin'] ?? null;
        $total = $market['market_total'] ?? null;

        return implode('|', [
            $context['cohort'] ?? 'unknown', json_encode($context['qb_status'] ?? ['unknown', 'unknown']),
            is_numeric($margin) ? ($margin >= 0 ? 'home_fav' : 'away_fav') : 'unknown_favorite',
            ! is_numeric($margin) ? 'unknown_line' : (abs($margin) <= 3 ? 'small' : (abs($margin) <= 7 ? 'medium' : 'large')),
            ! is_numeric($total) ? 'unknown_total' : ($total <= 40 ? 'low' : ($total <= 48 ? 'medium' : 'high')),
            $week === null ? 'unknown_week' : ($week <= 4 ? 'early' : ($week <= 9 ? 'middle' : 'late')),
        ]);
    }
}
