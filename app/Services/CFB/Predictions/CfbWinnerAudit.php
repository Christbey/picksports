<?php

namespace App\Services\CFB\Predictions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Winner-first exploratory audit. No forecast, approval, coefficient, or calibration is promoted by this report. */
class CfbWinnerAudit
{
    public function __construct(private readonly CfbWinnerMatchupCandidate $candidate) {}

    public function evaluate(array $dataset, string $validationFrom, string $testFrom, array $rivalryPairs = []): array
    {
        $validation = $this->date($validationFrom);
        $test = $this->date($testFrom);
        if ($validation->gte($test) || ($dataset['schema'] ?? null) !== 'cfb-winner-audit-dataset-v1') {
            throw new InvalidArgumentException('A versioned frozen dataset and ordered validation/test dates are required.');
        }
        foreach ($rivalryPairs as $pair) {
            if (! preg_match('/^([A-Za-z0-9-]+):([A-Za-z0-9-]+)$/', $pair, $teams) || $teams[1] === $teams[2]) {
                throw new InvalidArgumentException('Rivalry pairs must contain two distinct team abbreviations separated by a colon.');
            }
        }
        $rows = $seen = $excluded = [];
        foreach ($dataset['rows'] ?? [] as $row) {
            if (! isset($row['game_id'], $row['prediction_id'], $row['release'], $row['configuration_hash'])
                || isset($seen[$row['game_id']]) || ($row['pregame_safe'] ?? false) !== true
                || ! isset($row['captured_at'], $row['kickoff'])
                || CarbonImmutable::parse($row['captured_at'])->gte(CarbonImmutable::parse($row['kickoff']))) {
                throw new InvalidArgumentException('Unique game IDs and verified frozen pregame evidence are required.');
            }
            $seen[$row['game_id']] = true;
            if (! $this->finite($row['home_probability'] ?? null) || $row['home_probability'] <= 0 || $row['home_probability'] >= 1
                || ! $this->finite($row['home_score'] ?? null) || ! $this->finite($row['away_score'] ?? null)
                || $row['home_score'] < 0 || $row['away_score'] < 0 || $row['home_score'] == $row['away_score']) {
                $excluded['invalid_probability_or_final_result'] = ($excluded['invalid_probability_or_final_result'] ?? 0) + 1;

                continue;
            }
            $row['home_probability'] = (float) $row['home_probability'];
            $row['day'] = CarbonImmutable::parse($row['kickoff'])->setTimezone('America/Chicago')->toDateString();
            $row['split'] = $row['day'] < $validationFrom ? 'train' : ($row['day'] < $testFrom ? 'validation' : 'test');
            $row['actual_home_win'] = $row['home_score'] > $row['away_score'];
            $row['correct'] = $row['home_probability'] == 0.5 ? null : (($row['home_probability'] > 0.5) === $row['actual_home_win']);
            $row['rivalry'] = $this->rivalry($row, $rivalryPairs);
            $row['matchup_features_complete'] = $this->candidate->features($row) !== null;
            $row['loss_context'] = $this->context($row);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => CarbonImmutable::parse($a['kickoff'])->getTimestamp() <=> CarbonImmutable::parse($b['kickoff'])->getTimestamp() ?: $a['game_id'] <=> $b['game_id']);
        $cohorts = ['all' => $rows];
        foreach ($rows as $row) {
            foreach (['release:'.$row['release'], 'configuration:'.$row['release'].':'.$row['configuration_hash'],
                'split:'.$row['split'], 'rivalry:'.$row['rivalry'], 'quality:'.(($row['quality']['qualified'] ?? false) ? 'qualified' : 'unqualified')] as $key) {
                $cohorts[$key][] = $row;
            }
        }
        $reports = [];
        foreach ($cohorts as $key => $cohort) {
            $reports[$key] = ['performance' => $this->summarize($cohort), 'paired_baselines' => $this->pairs($cohort)];
        }
        $contexts = [];
        foreach ($rows as $row) {
            foreach ($row['loss_context'] as $flag) {
                $contexts[$flag][] = $row;
            }
        }

        return ['version' => 'cfb-winner-audit-v1', 'source' => $dataset['source'] ?? null,
            'source_queried_at' => $dataset['queried_at'] ?? null, 'dataset_sha256' => hash('sha256', json_encode($dataset, JSON_THROW_ON_ERROR)),
            'objective' => 'outright_winner_accuracy_then_probability_reliability', 'games' => count($rows),
            'excluded' => ['export' => $dataset['excluded'] ?? [], 'evaluation' => $excluded],
            'split_dates_central' => ['validation_from' => $validationFrom, 'test_from' => $testFrom],
            'cohorts' => $reports, 'loss_context_associations' => array_map(fn ($v) => $this->summarize($v), $contexts),
            'integrity' => ['probability_margin_direction_disagreements' => array_column(array_values(array_filter($rows, fn ($r) => $this->finite($r['home_margin'] ?? null) && $r['home_margin'] != 0 && $r['home_probability'] != 0.5
                && (($r['home_margin'] > 0) !== ($r['home_probability'] > 0.5)))), 'game_id')],
            'feature_coverage' => $this->coverage($rows), 'temporal_challengers' => $this->challengers($rows),
            'selection_ranking' => $this->rankings($rows),
            'losses' => array_values(array_filter($rows, fn ($r) => $r['correct'] === false)),
            'promotion' => ['allowed' => false, 'probabilities_calibrated' => false, 'confidence_labels_validated' => false,
                'reason' => 'Exploratory retrospective audit after observing results; a new untouched prospective evaluation is required.'],
            'limitations' => ['Loss contexts are associations, not causal diagnoses.',
                'Published versus independent isolates the frozen coordinator, not a complete previous-release replay.',
                'Market and FPI may retain indirect market assumptions; removing the explicit blend does not remove these.',
                'Rivalry pairs are user-designated context only; unlisted games are not certified non-rivalries.',
                'Ranking and feature recipes were specified after observing historical outcomes; no automatic promotion.',
                'Wilson intervals describe sampling uncertainty and do not validate a confidence label.']];
    }

    public function summarize(array $rows): array
    {
        $wins = $losses = $undecided = 0;
        $brier = $logLoss = 0.0;
        $bands = [];
        foreach ($rows as $row) {
            $p = (float) $row['home_probability'];
            $y = $row['home_score'] > $row['away_score'] ? 1 : 0;
            $correct = $p == 0.5 ? null : (($p > 0.5) === (bool) $y);
            if ($correct === null) {
                $undecided++;
            } elseif ($correct) {
                $wins++;
            } else {
                $losses++;
            }
            $brier += ($p - $y) ** 2;
            $logLoss -= $y * log($p) + (1 - $y) * log(1 - $p);
            if ($correct !== null) {
                $confidence = max($p, 1 - $p);
                $band = min(9, (int) floor($confidence * 10));
                $bands[$band][] = ['probability' => $confidence, 'correct' => $correct];
            }
        }
        ksort($bands);
        $reliability = [];
        $ece = 0.0;
        foreach ($bands as $band => $examples) {
            $n = count($examples);
            $w = count(array_filter($examples, fn ($e) => $e['correct']));
            $predicted = array_sum(array_column($examples, 'probability')) / $n;
            $ece += abs($predicted - $w / $n) * $n;
            $reliability[] = ['band' => ($band * 10).'-'.($band === 9 ? 100 : ($band + 1) * 10),
                'games' => $n, 'wins' => $w, 'mean_raw_estimate' => $predicted, 'observed_win_rate' => $w / $n,
                'observed_wilson_95' => $this->wilson($w, $n), 'minimum_30_games_met' => $n >= 30,
                'confidence_validated' => false];
        }
        $n = count($rows);

        return ['games' => $n, 'wins' => $wins, 'losses' => $losses, 'undecided' => $undecided,
            'accuracy' => $wins + $losses ? $wins / ($wins + $losses) : null,
            'accuracy_wilson_95' => $this->wilson($wins, $wins + $losses),
            'brier' => $n ? $brier / $n : null, 'log_loss' => $n ? $logLoss / $n : null,
            'favorite_confidence_ece' => $wins + $losses ? $ece / ($wins + $losses) : null,
            'reliability' => $reliability];
    }

    private function pairs(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['models'] ?? []) as $name) {
                $names[$name] = true;
            }
        }
        $reports = [];
        foreach (array_keys($names) as $name) {
            $wins = $candidateWins = $onlyPublished = $onlyCandidate = 0;
            $ids = [];
            foreach ($rows as $row) {
                $margin = $row['models'][$name]['margin'] ?? null;
                if (! $this->finite($margin) || (float) $margin === 0.0 || $row['correct'] === null) {
                    continue;
                }
                $correct = ($margin > 0) === $row['actual_home_win'];
                $ids[] = $row['game_id'];
                $wins += (int) $row['correct'];
                $candidateWins += (int) $correct;
                $onlyPublished += (int) ($row['correct'] && ! $correct);
                $onlyCandidate += (int) (! $row['correct'] && $correct);
            }
            $reports[$name] = ['games' => count($ids), 'game_ids' => $ids, 'published_wins' => $wins,
                'candidate_wins' => $candidateWins, 'published_only_correct' => $onlyPublished,
                'candidate_only_correct' => $onlyCandidate,
                'accuracy_delta_candidate_minus_published' => $ids ? ($candidateWins - $wins) / count($ids) : null];
        }

        return $reports;
    }

    private function challengers(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['release'].':'.$row['configuration_hash']][] = $row;
        }
        $reports = [];
        foreach ($groups as $key => $group) {
            foreach (['probability_only' => false, 'overall_matchup' => true] as $name => $matchups) {
                $eligible = array_values(array_filter($group, fn ($r) => ! $matchups || $r['matchup_features_complete']));
                $splits = [];
                foreach (['train', 'validation', 'test'] as $split) {
                    $splits[$split] = array_values(array_filter($eligible, fn ($r) => $r['split'] === $split));
                }
                $counts = array_map('count', $splits);
                if ($counts['train'] < 100 || $counts['validation'] < 50 || $counts['test'] < 50) {
                    $reports[$key][$name] = ['status' => 'insufficient_evidence', 'counts' => $counts,
                        'required' => ['train' => 100, 'validation' => 50, 'test' => 50]];

                    continue;
                }
                $model = $this->candidate->fit($splits['train'], $matchups);
                $evaluation = [];
                foreach (['validation', 'test'] as $split) {
                    $candidate = array_map(fn ($r) => [...$r, 'home_probability' => $this->candidate->predict($r, $model)], $splits[$split]);
                    $evaluation[$split] = ['published' => $this->summarize($splits[$split]), 'candidate' => $this->summarize($candidate),
                        'game_ids' => array_column($splits[$split], 'game_id')];
                    $base = $evaluation[$split]['published'];
                    $challenger = $evaluation[$split]['candidate'];
                    $evaluation[$split]['winner_first_checks'] = [
                        'winner_accuracy_not_worse' => $challenger['accuracy'] !== null && $base['accuracy'] !== null && $challenger['accuracy'] >= $base['accuracy'],
                        'brier_improves' => $challenger['brier'] < $base['brier'],
                        'log_loss_improves' => $challenger['log_loss'] < $base['log_loss'],
                        'favorite_confidence_ece_at_most_0_05' => $challenger['favorite_confidence_ece'] !== null && $challenger['favorite_confidence_ece'] <= 0.05,
                    ];
                }
                $reports[$key][$name] = ['status' => 'exploratory_challenger', 'counts' => $counts, 'model' => $model,
                    'evaluation' => $evaluation, 'automatic_promotion' => false];
            }
        }

        return $reports;
    }

    private function rankings(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            if ($row['split'] !== 'train' && $row['correct'] !== null) {
                $days[$row['day']][] = $row;
            }
        }
        $policies = ['raw_probability', 'qualified_raw_probability', 'result_model_agreement', 'complete_matchup_evidence'];
        $reports = [];
        foreach ($policies as $policy) {
            $selected = $remainder = [];
            $daily = [];
            foreach ($days as $day => $group) {
                $eligible = array_values(array_filter($group, function ($r) use ($policy) {
                    if ($policy === 'qualified_raw_probability') {
                        return ($r['quality']['qualified'] ?? false) === true;
                    }
                    if ($policy === 'complete_matchup_evidence') {
                        return $r['matchup_features_complete'];
                    }
                    if ($policy === 'result_model_agreement') {
                        $m = $r['models']['result_rating']['margin'] ?? null;

                        return $this->finite($m) && (float) $m !== 0.0 && (($m > 0) === ($r['home_probability'] > 0.5));
                    }

                    return true;
                }));
                usort($eligible, fn ($a, $b) => max($b['home_probability'], 1 - $b['home_probability']) <=> max($a['home_probability'], 1 - $a['home_probability']) ?: $a['game_id'] <=> $b['game_id']);
                $top = array_slice($eligible, 0, 5);
                $rest = array_slice($eligible, 5);
                $selected = [...$selected, ...$top];
                $remainder = [...$remainder, ...$rest];
                $daily[$day] = ['eligible' => count($eligible), 'selected_game_ids' => array_column($top, 'game_id')];
            }
            $reports[$policy] = ['top_5_per_day' => $this->summarize($selected), 'eligible_remainder' => $this->summarize($remainder),
                'selected_by_split' => ['validation' => $this->summarize(array_values(array_filter($selected, fn ($r) => $r['split'] === 'validation'))),
                    'test' => $this->summarize(array_values(array_filter($selected, fn ($r) => $r['split'] === 'test')))],
                'daily' => $daily, 'validated' => false, 'ranking_rule' => 'raw_probability_descending_game_id_tiebreak_no_outcome_sort'];
        }

        return $reports;
    }

    private function context(array $row): array
    {
        if ($row['home_probability'] == 0.5) {
            return ['no_direction'];
        }
        $picked = $row['home_probability'] > 0.5 ? 'home' : 'away';
        $opponent = $picked === 'home' ? 'away' : 'home';
        $p = $row[$picked.'_metrics'] ?? [];
        $o = $row[$opponent.'_metrics'] ?? [];
        $flags = ($row['quality']['qualified'] ?? false) ? [] : ['unqualified_inputs'];
        foreach (['strength_of_schedule' => 'picked_team_easier_schedule', 'offensive_success_rate' => 'picked_team_lower_offensive_success'] as $field => $flag) {
            if ($this->finite($p[$field] ?? null) && $this->finite($o[$field] ?? null) && $p[$field] < $o[$field]) {
                $flags[] = $flag;
            }
        }
        if ($this->finite($p['defensive_success_rate'] ?? null) && $this->finite($o['defensive_success_rate'] ?? null)
            && $p['defensive_success_rate'] > $o['defensive_success_rate']) {
            $flags[] = 'picked_team_higher_defensive_success_allowed';
        }
        $m = $row['models']['result_rating']['margin'] ?? null;
        if ($this->finite($m) && (float) $m !== 0.0 && (($m > 0) !== ($row['home_probability'] > 0.5))) {
            $flags[] = 'result_model_winner_disagreement';
        }
        if (max($row['home_probability'], 1 - $row['home_probability']) >= 0.9) {
            $flags[] = 'raw_estimate_90_plus';
        }

        return $flags;
    }

    private function coverage(array $rows): array
    {
        $fields = ['offensive_success_rate', 'defensive_success_rate', 'offensive_true_epa_per_play', 'defensive_true_epa_per_play',
            'strength_of_schedule', 'offensive_sack_rate', 'defensive_sack_rate', 'passing_yards_allowed_per_game', 'rushing_yards_allowed_per_game'];
        $coverage = [];
        foreach ($fields as $field) {
            $coverage[$field] = count(array_filter($rows, fn ($r) => $this->finite($r['home_metrics'][$field] ?? null) && $this->finite($r['away_metrics'][$field] ?? null)));
        }

        return ['both_teams_numeric' => $coverage, 'complete_current_season_matchup_games' => count(array_filter($rows, fn ($r) => $r['matchup_features_complete'])),
            'interpretation' => 'Presence is not independent source validation. No pass/rush/pressure effects are imputed when missing.'];
    }

    private function rivalry(array $row, array $pairs): string
    {
        foreach ($pairs as $pair) {
            $teams = explode(':', $pair);
            if (count($teams) !== 2 || $teams[0] === $teams[1]) {
                throw new InvalidArgumentException('Rivalry pairs must contain two distinct team abbreviations separated by a colon.');
            }
            if (in_array($row['home'], $teams, true) && in_array($row['away'], $teams, true)) {
                return $pair;
            }
        }

        return 'not_in_user_designated_pairs';
    }

    private function wilson(int $wins, int $n): ?array
    {
        if ($n === 0) {
            return null;
        }
        $p = $wins / $n;
        $z = 1.96;
        $denominator = 1 + $z ** 2 / $n;
        $center = ($p + $z ** 2 / (2 * $n)) / $denominator;
        $half = $z * sqrt($p * (1 - $p) / $n + $z ** 2 / (4 * $n ** 2)) / $denominator;

        return [max(0, $center - $half), min(1, $center + $half)];
    }

    private function date(string $date): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Chicago')->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Valid YYYY-MM-DD boundaries are required.');
        }

        return CarbonImmutable::parse($date, 'America/Chicago');
    }

    private function finite(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }
}
