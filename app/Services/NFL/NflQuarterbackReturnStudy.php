<?php

namespace App\Services\NFL;

/** Descriptive return-to-start cohort. A gap alone NEVER proves an injury. */
final class NflQuarterbackReturnStudy
{
    /** Chronological regular-season games with an optional verified home handicap. */
    public function analyze(array $games): array
    {
        usort($games, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $history = $returns = [];
        foreach ($games as $g) {
            foreach (['home', 'away'] as $side) {
                $team = $g[$side.'_team_id'];
                $qb = trim((string) ($g[$side.'_qb_id'] ?? ''));
                $prior = array_slice($history[$team] ?? [], -16);
                $last = null;
                for ($i = count($prior) - 1; $i >= 0; $i--) {
                    if ($qb !== '' && $prior[$i]['qb'] === $qb) {
                        $last = $i;
                        break;
                    }
                }
                $gap = $last === null ? [] : array_slice($prior, $last + 1);
                $starts = count(array_filter($prior, fn ($r) => $r['qb'] === $qb));
                $knownGap = ! in_array('', array_column($gap, 'qb'), true);
                // Require an established QB, a consecutive starting stint before
                // the gap, 1–8 missed team games, and no skipped unknown starters.
                if ($last !== null && $last > 0 && $prior[$last - 1]['qb'] === $qb
                    && $starts >= 4 && count($gap) >= 1 && count($gap) <= 8 && $knownGap
                    && $g['season'] - $prior[$last]['season'] <= 1) {
                    $sign = $side === 'home' ? 1 : -1;
                    $margin = $sign * ($g['home_score'] - $g['away_score']);
                    $line = isset($g['home_line']) ? $sign * $g['home_line'] : null;
                    $returns[] = ['game_id' => $g['id'], 'date' => $g['date'], 'season' => $g['season'],
                        'team_id' => $team, 'qb_id' => $qb, 'qb' => $g[$side.'_qb_name'] ?? $qb,
                        'venue' => $side, 'missed_games' => count($gap), 'prior_starts_last_16' => $starts,
                        'cross_season' => $g['season'] !== $prior[$last]['season'],
                        'absence_reason' => 'unverified', 'injury_verified' => false,
                        'margin' => $margin, 'line' => $line,
                        'straight_up' => $this->outcome($margin),
                        'ats' => $line === null ? null : $this->outcome($margin + $line)];
                }
                $history[$team][] = ['qb' => $qb, 'season' => $g['season']];
            }
        }

        return ['affects_prediction' => false, 'cohort' => 'established_qb_return_to_start_not_injury_verified',
            'definition' => '4+ starts in prior 16 team games; last two starts before absence by this QB; 1–8 intervening games with known other starters; same team; at most one offseason.',
            'coverage' => ['games' => count($games), 'return_events' => count($returns), 'injury_verified_events' => 0],
            'all' => $this->record($returns),
            'since_2020' => $this->record(array_filter($returns, fn ($r) => $r['season'] >= 2020)),
            'one_missed' => $this->record(array_filter($returns, fn ($r) => $r['missed_games'] === 1)),
            'two_plus_missed' => $this->record(array_filter($returns, fn ($r) => $r['missed_games'] >= 2)),
            'same_season' => $this->record(array_filter($returns, fn ($r) => ! $r['cross_season'])),
            'cross_season' => $this->record(array_filter($returns, fn ($r) => $r['cross_season'])),
            'events' => $returns];
    }

    private function record(array $rows): array
    {
        $record = ['events' => count($rows), 'su_wins' => 0, 'su_losses' => 0, 'su_ties' => 0,
            'ats_wins' => 0, 'ats_losses' => 0, 'ats_pushes' => 0, 'missing_line' => 0];
        foreach ($rows as $row) {
            $record['su_'.match ($row['straight_up']) {
                'win' => 'wins', 'loss' => 'losses', default => 'ties'
            }]++;
            $key = match ($row['ats']) {
                'win' => 'ats_wins', 'loss' => 'ats_losses', 'push' => 'ats_pushes', default => 'missing_line'
            };
            $record[$key]++;
        }

        return $record;
    }

    private function outcome(float $margin): string
    {
        return abs($margin) < 0.00001 ? 'push' : ($margin > 0 ? 'win' : 'loss');
    }
}
