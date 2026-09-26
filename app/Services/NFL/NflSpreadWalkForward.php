<?php

namespace App\Services\NFL;

use Carbon\CarbonImmutable;

class NflSpreadWalkForward
{
    /**
     * Uses recorded forecasts, not present-day regeneration of historical games.
     * All candidates must supply actual as-of forecasts; missing variants stay missing.
     */
    public function evaluate(array $rows): array
    {
        $accepted = [];
        $excluded = [];
        foreach ($rows as $row) {
            // Normalize offsets before any temporal comparisons. Naive database dates are UTC.
            try {
                foreach (['kickoff', 'generated_at', 'result_available_at', 'quote_at'] as $key) {
                    if (! isset($row[$key]) || ! is_string($row[$key]) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $row[$key])) {
                        throw new \InvalidArgumentException('Invalid timestamp');
                    }
                    $row[$key] = CarbonImmutable::parse($row[$key], 'UTC')->utc()->format('Y-m-d H:i:s.u');
                    $errors = CarbonImmutable::getLastErrors();
                    if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                        throw new \InvalidArgumentException('Invalid calendar date');
                    }
                }
            } catch (\Throwable) {
                $excluded['invalid_timestamp'] = ($excluded['invalid_timestamp'] ?? 0) + 1;

                continue;
            }
            $reason = $this->invalidReason($row);
            if ($reason !== null) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;

                continue;
            }
            $id = (string) $row['game_id'];
            if (! isset($accepted[$id]) || $row['generated_at'] > $accepted[$id]['generated_at']) {
                $accepted[$id] = $row;
            }
        }
        usort($accepted, fn ($a, $b) => strcmp($a['kickoff'], $b['kickoff']));
        $variants = ['market', 'recorded_model', 'elo_market', 'validated_epa_market'];
        $reports = $predictions = $coverage = [];
        foreach ($variants as $variant) {
            $reports[$variant] = [];
            $coverage[$variant] = ['available' => 0, 'missing' => 0];
            $history = [];
            foreach ($accepted as $row) {
                $margin = $this->margin($row, $variant);
                if ($margin === null) {
                    $coverage[$variant]['missing']++;

                    continue;
                }
                $coverage[$variant]['available']++;
                // Do not mix residual distributions across releases or fit on simultaneous games.
                $residuals = array_values(array_map(fn ($h) => $h['residual'], array_filter($history,
                    fn ($h) => $h['available_at'] < $row['generated_at']
                        && $h['kickoff'] < $row['kickoff'] && $h['model_version'] === $row['model_version'])));
                $estimate = (new NflSpreadProbability)->estimate($residuals, $margin, $row['home_line']);
                $result = (new NflSpreadBacktestEvaluator)->evaluate($margin, $row['home_line'], $row['actual_margin']);
                $buckets = ['all', 'season_'.$row['season'], 'version_'.$row['model_version'], $row['week'] <= 4 ? 'weeks_1_4' : 'weeks_5_plus'];
                if (collect($variants)->every(fn ($candidate) => $this->margin($row, $candidate) !== null)) {
                    $buckets[] = 'common_all_variants';
                }
                if ($this->margin($row, 'elo_market') !== null) {
                    $buckets[] = 'common_market_model_elo';
                }
                foreach ($buckets as $bucket) {
                    $r = $reports[$variant][$bucket] ?? ['games' => 0, 'wins' => 0, 'losses' => 0, 'pushes' => 0,
                        'no_pick' => 0, 'absolute_error_sum' => 0.0, 'probability_games' => 0, 'brier_sum' => 0.0];
                    $r['games']++;
                    $r[match ($result['result']) {
                        'win' => 'wins', 'loss' => 'losses', 'push' => 'pushes', default => 'no_pick'
                    }]++;
                    $r['absolute_error_sum'] += abs($row['actual_margin'] - $margin);
                    if ($estimate['conditional_cover_probability'] !== null && in_array($result['result'], ['win', 'loss'], true)) {
                        $r['probability_games']++;
                        $r['brier_sum'] += ($estimate['conditional_cover_probability'] - ($result['won'] ? 1 : 0)) ** 2;
                    }
                    $reports[$variant][$bucket] = $r;
                }
                $predictions[] = ['game_id' => $row['game_id'], 'variant' => $variant,
                    'model_version' => $row['model_version'], 'training_samples' => count($residuals),
                    'prediction' => $estimate, 'result' => $result['result']];
                $history[] = ['available_at' => $row['result_available_at'], 'kickoff' => $row['kickoff'],
                    'model_version' => $row['model_version'], 'residual' => $row['actual_margin'] - $margin];
            }
        }
        foreach ($reports as &$buckets) {
            foreach ($buckets as &$r) {
                $r['mae'] = $r['absolute_error_sum'] / $r['games'];
                $r['win_rate'] = $r['wins'] + $r['losses'] > 0 ? $r['wins'] / ($r['wins'] + $r['losses']) : null;
                $r['brier'] = $r['probability_games'] > 0 ? $r['brier_sum'] / $r['probability_games'] : null;
                unset($r['absolute_error_sum'], $r['brier_sum']);
            }
            unset($r);
        }
        unset($buckets);

        return ['version' => 'nfl-spread-walk-forward-v1', 'accepted_games' => count($accepted),
            'excluded' => $excluded, 'coverage' => $coverage, 'reports' => $reports, 'predictions' => $predictions,
            'promotion_allowed' => false, 'note' => 'Exploratory chronological evaluation; no automatic weight tuning or promotion. Compare variants on common game IDs.'];
    }

    private function margin(array $row, string $variant): ?float
    {
        if ($variant === 'market') {
            return -$row['home_line'];
        }
        $field = match ($variant) {
            'recorded_model' => 'model_margin', 'elo_market' => 'elo_market_margin', default => 'validated_epa_market_margin'
        };

        return isset($row[$field]) && is_numeric($row[$field]) && is_finite((float) $row[$field]) ? (float) $row[$field] : null;
    }

    private function invalidReason(array $row): ?string
    {
        foreach (['game_id', 'season', 'week', 'model_version', 'kickoff', 'generated_at', 'result_available_at', 'quote_at'] as $key) {
            if (! isset($row[$key]) || $row[$key] === '') {
                return 'missing_'.$key;
            }
        }
        foreach (['home_line', 'model_margin', 'actual_margin'] as $key) {
            if (! isset($row[$key]) || ! is_numeric($row[$key]) || ! is_finite((float) $row[$key])) {
                return 'missing_or_invalid_'.$key;
            }
        }
        if (abs($row['home_line'] * 2 - round($row['home_line'] * 2)) > 0.00001
            || abs($row['home_line']) > 21 || (float) $row['actual_margin'] !== (float) round($row['actual_margin'])) {
            return 'invalid_line_or_score';
        }
        if ($row['generated_at'] >= $row['kickoff'] || $row['quote_at'] > $row['generated_at']) {
            return 'not_observed_pregame';
        }
        if ($row['result_available_at'] <= $row['kickoff']) {
            return 'invalid_result_availability';
        }

        return null;
    }
}
