<?php

namespace App\Services\CFB\Live\Overtime;

use App\Models\CFB\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class OvertimeProjector
{
    public const VERSION = 'cfb-overtime-possession-v1';

    public function project(Game $game): array
    {
        $state = $game->overtime_state;
        if (! is_array($state) || ($state['status'] ?? '') !== 'observed'
            || ($state['period'] ?? null) !== (int) $game->period
            || ($state['home_score'] ?? null) !== (int) $game->home_score
            || ($state['away_score'] ?? null) !== (int) $game->away_score
            || ! isset($state['observed_at']) || CarbonImmutable::parse($state['observed_at'])->lt(now()->subMinutes(3))
            || CarbonImmutable::parse($state['observed_at'])->isFuture()) {
            return ['status' => 'overtime_missing_possession', 'projection' => null];
        }
        $completed = $state['completed_possessions'];
        if (count($completed) === 2 && $game->home_score !== $game->away_score) {
            return ['status' => 'live', 'projection' => $this->projection($game->home_score, $game->away_score,
                $game->home_score > $game->away_score ? 1.0 : 0.0, 'rules_determined', 0, $state)];
        }
        if (count($completed) >= 2 || ! in_array($state['possession'] ?? null, ['home', 'away'], true)
            || ! is_numeric($state['down'] ?? null) || $state['down'] < 1 || $state['down'] > 4 || ! is_numeric($state['distance'] ?? null) || ! is_numeric($state['yards_to_endzone'] ?? null)) {
            return ['status' => 'overtime_missing_possession', 'projection' => null];
        }
        $samples = $this->samples($game, $state);
        if (count($samples) < 30) {
            return ['status' => 'overtime_insufficient_history', 'projection' => null, 'sample_games' => count($samples)];
        }
        $home = $game->home_score + array_sum(array_column($samples, 'home_remaining')) / count($samples);
        $away = $game->away_score + array_sum(array_column($samples, 'away_remaining')) / count($samples);

        return ['status' => 'live', 'projection' => $this->projection($home, $away,
            array_sum(array_column($samples, 'home_win')) / count($samples), 'uncalibrated_empirical_overtime', count($samples), $state, array_column($samples, 'game_id'))];
    }

    /** One observation per independent historical game, with no same-game outcomes. */
    private function samples(Game $game, array $state): array
    {
        $history = Cache::remember('cfb-ot-history-v1:'.now()->format('Y-m-d'), 3600, fn () => Game::query()
            ->where('season', '>=', 2021)->where('status', 'STATUS_FINAL')->whereHas('plays', fn ($q) => $q->where('period', '>', 4))
            ->where('game_date', '<', now()->startOfDay())->with(['plays' => fn ($q) => $q->where('period', '>=', 4)
            ->select(['id', 'game_id', 'sequence_number', 'period', 'possession_team_id', 'home_score', 'away_score', 'down', 'distance', 'yards_to_endzone'])
            ->orderBy('sequence_number')])
            ->get()->map(fn ($g) => ['id' => $g->id, 'date' => $g->game_date->toDateString(), 'home_id' => $g->home_team_id,
                'home_final' => $g->home_score, 'away_final' => $g->away_score,
                'plays' => $g->plays->map->only(['period', 'possession_team_id', 'home_score', 'away_score', 'down', 'distance', 'yards_to_endzone'])->all()])->all());
        $homePossession = $state['possession'] === 'home';
        $margin = $homePossession ? $game->home_score - $game->away_score : $game->away_score - $game->home_score;
        $samples = [];
        foreach ($history as $row) {
            if ($row['id'] === $game->id || $row['date'] >= $game->game_date->toDateString()) {
                continue;
            }
            $previous = null;
            $seen = [];
            foreach ($row['plays'] as $play) {
                $period = (int) $play['period'];
                if ($period < 5) {
                    $previous = $play;

                    continue;
                }
                $team = $play['possession_team_id'];
                if ($team === null || ! is_numeric($play['down']) || $play['down'] < 1 || $play['down'] > 4
                    || ! is_numeric($play['yards_to_endzone']) || $play['yards_to_endzone'] <= 0) {
                    $previous = $play;

                    continue;
                }
                $seen[$period] ??= [];
                $second = count(array_diff($seen[$period], [$team])) > 0;
                $seen[$period][] = $team;
                $seen[$period] = array_unique($seen[$period]);
                $offenseHome = (int) $team === (int) $row['home_id'];
                $preHome = $previous['home_score'] ?? null;
                $preAway = $previous['away_score'] ?? null;
                if ($period === (int) $game->period && $previous !== null && is_numeric($preHome) && is_numeric($preAway)
                    && $second === (count($state['completed_possessions']) === 1)
                    && is_numeric($play['down']) && (int) $play['down'] === (int) $state['down']
                    && is_numeric($play['distance']) && abs($play['distance'] - $state['distance']) <= 2
                    && is_numeric($play['yards_to_endzone']) && abs($play['yards_to_endzone'] - $state['yards_to_endzone']) <= 3
                    && ($offenseHome ? $preHome - $preAway : $preAway - $preHome) === $margin
                    && $row['home_final'] >= $preHome && $row['away_final'] >= $preAway) {
                    $sameOrientation = $offenseHome === $homePossession;
                    $samples[] = ['game_id' => $row['id'], 'home_remaining' => $sameOrientation ? $row['home_final'] - $preHome : $row['away_final'] - $preAway,
                        'away_remaining' => $sameOrientation ? $row['away_final'] - $preAway : $row['home_final'] - $preHome,
                        'home_win' => ($sameOrientation ? $row['home_final'] > $row['away_final'] : $row['away_final'] > $row['home_final']) ? 1 : 0];
                    break;
                }
                $previous = $play;
            }
        }

        return $samples;
    }

    private function projection(float $home, float $away, float $probability, string $status, int $samples, array $state, array $gameIds = []): array
    {
        $home = round($home, 1);
        $away = round($away, 1);

        return ['spread' => round($home - $away, 1), 'total' => round($home + $away, 1), 'home_points' => $home, 'away_points' => $away,
            'home_win_probability' => round($probability, 3), 'seconds_remaining' => null,
            'model_version' => self::VERSION, 'probability_status' => $status,
            'overtime' => ['state' => $state, 'sample_games' => $samples, 'sample_game_ids' => $gameIds, 'method' => $status === 'rules_determined' ? 'confirmed_completed_possessions' : 'matched_historical_possession_outcomes']];
    }
}
