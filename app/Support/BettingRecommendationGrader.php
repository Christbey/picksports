<?php

namespace App\Support;

class BettingRecommendationGrader
{
    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<string, mixed>
     */
    public function grade(array $recommendation, object $game, object $prediction, string $sportKey): array
    {
        $type = (string) ($recommendation['type'] ?? '');
        $edge = (float) ($recommendation['edge'] ?? 0);
        $riskFlags = $this->baseRiskFlags($game, $prediction, $sportKey);

        if ($type === 'spread') {
            $riskFlags = array_merge($riskFlags, $this->spreadRiskFlags($recommendation, $sportKey));
            $grade = $this->spreadGrade($edge, $riskFlags, $sportKey);
            $units = $this->spreadUnits($grade, $edge, $riskFlags);
        } elseif ($type === 'total') {
            $grade = $this->totalGrade($edge, $riskFlags, $sportKey);
            $units = $this->totalUnits($grade, $edge, $riskFlags);
        } elseif ($type === 'moneyline') {
            $grade = $this->moneylineGrade($edge, $riskFlags, $sportKey);
            $units = (float) min((float) ($recommendation['kelly_bet_size_percent'] ?? 0) / 2, $this->maxUnits($sportKey));
        } else {
            $grade = 'Pass';
            $units = 0.0;
        }

        $riskFlags = array_values(array_unique(array_filter($riskFlags)));

        return [
            ...$recommendation,
            'grade' => $grade,
            'risk_level' => $this->riskLevel($grade, $riskFlags),
            'risk_flags' => $riskFlags,
            'bet_units' => round(max(0.0, $units), 2),
            'recommendation_strength' => $this->strength($grade),
            'is_playable' => $grade !== 'Pass',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function baseRiskFlags(object $game, object $prediction, string $sportKey): array
    {
        $flags = [];
        $metadata = is_array($prediction->model_metadata ?? null) ? $prediction->model_metadata : [];

        if (($metadata['true_epa']['applied'] ?? null) === false) {
            $flags[] = 'missing_true_epa';
        }

        if (($metadata['market_blend']['applied'] ?? null) !== true) {
            $flags[] = 'no_market_blend';
        }

        if (($metadata['depth_chart_injuries']['has_unscoped_injury_uncertainty'] ?? false) === true) {
            $flags[] = 'injury_date_uncertainty';
        }

        if ((int) ($game->week ?? 99) <= (int) config("{$sportKey}.betting.risk.early_season_weeks", 2)) {
            $flags[] = 'early_season';
        }

        if (empty($game->odds_updated_at)) {
            $flags[] = 'missing_odds_timestamp';
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<int, string>
     */
    private function spreadRiskFlags(array $recommendation, string $sportKey): array
    {
        $flags = [];
        $marketLine = abs((float) ($recommendation['market_line'] ?? 0));

        foreach ((array) config("{$sportKey}.betting.key_numbers", [3, 7, 10]) as $number) {
            if (abs($marketLine - (float) $number) < 0.001) {
                $flags[] = 'key_number_'.$this->keyNumberLabel((float) $number);
            }
        }

        if ((float) ($recommendation['edge'] ?? 0) <= (float) config("{$sportKey}.betting.edge_thresholds.spread", 2.5)) {
            $flags[] = 'threshold_edge';
        }

        return $flags;
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function spreadGrade(float $edge, array $riskFlags, string $sportKey): string
    {
        $threshold = (float) config("{$sportKey}.betting.edge_thresholds.spread", 2.5);

        return $this->gradeFromEdge($edge, $threshold, $riskFlags, 1.5, 3.0);
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function totalGrade(float $edge, array $riskFlags, string $sportKey): string
    {
        $threshold = (float) config("{$sportKey}.betting.edge_thresholds.total", 3.0);

        return $this->gradeFromEdge($edge, $threshold, $riskFlags, 1.5, 3.0);
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function moneylineGrade(float $edge, array $riskFlags, string $sportKey): string
    {
        $threshold = (float) config("{$sportKey}.betting.edge_thresholds.moneyline", 0.05) * 100;

        return $this->gradeFromEdge($edge, $threshold, $riskFlags, 2.0, 4.0);
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function gradeFromEdge(float $edge, float $threshold, array $riskFlags, float $bExtra, float $aExtra): string
    {
        if ($edge < $threshold) {
            return 'Pass';
        }

        $penalty = $this->riskPenalty($riskFlags);
        $score = $edge - $threshold - $penalty;

        return match (true) {
            $score >= $aExtra => 'A',
            $score >= $bExtra => 'B',
            default => 'C',
        };
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function riskPenalty(array $riskFlags): float
    {
        $penalty = 0.0;

        foreach ($riskFlags as $flag) {
            $penalty += match ($flag) {
                'missing_true_epa', 'injury_date_uncertainty' => 0.75,
                'early_season', 'missing_odds_timestamp' => 0.35,
                'threshold_edge' => 0.5,
                default => str_starts_with($flag, 'key_number_') ? 0.25 : 0.0,
            };
        }

        return $penalty;
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function spreadUnits(string $grade, float $edge, array $riskFlags): float
    {
        return $this->units($grade, $edge, $riskFlags);
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function totalUnits(string $grade, float $edge, array $riskFlags): float
    {
        return $this->units($grade, $edge, $riskFlags);
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function units(string $grade, float $edge, array $riskFlags): float
    {
        $base = match ($grade) {
            'A' => 1.5,
            'B' => 1.0,
            'C' => 0.5,
            default => 0.0,
        };

        if (in_array('injury_date_uncertainty', $riskFlags, true)) {
            $base -= 0.25;
        }

        if (in_array('threshold_edge', $riskFlags, true)) {
            $base -= 0.25;
        }

        return min($base + max(0, ($edge - 4.0) * 0.1), 2.0);
    }

    private function maxUnits(string $sportKey): float
    {
        return (float) config("{$sportKey}.betting.max_units", 2.0);
    }

    /**
     * @param  array<int, string>  $riskFlags
     */
    private function riskLevel(string $grade, array $riskFlags): string
    {
        if ($grade === 'Pass') {
            return 'avoid';
        }

        if (in_array('injury_date_uncertainty', $riskFlags, true) || in_array('missing_true_epa', $riskFlags, true)) {
            return $grade === 'A' ? 'medium' : 'elevated';
        }

        return $grade === 'C' ? 'medium' : 'standard';
    }

    private function strength(string $grade): string
    {
        return match ($grade) {
            'A' => 'strong_play',
            'B' => 'play',
            'C' => 'lean',
            default => 'pass',
        };
    }

    private function keyNumberLabel(float $number): string
    {
        return str_replace('.', '_', rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.'));
    }
}
