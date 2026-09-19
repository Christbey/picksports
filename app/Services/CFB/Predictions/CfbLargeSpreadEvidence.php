<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Models\CFB\TeamStat;
use Carbon\CarbonImmutable;

/** Frozen descriptive covariates; no fitted effect or ATS probability is inferred. */
class CfbLargeSpreadEvidence
{
    public function forGame(Game $target, int $teamId, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): array
    {
        $games = Game::query()->where('id', '!=', $target->id)->where('status', 'STATUS_FINAL')
            ->whereBetween('season', [$target->season - 1, $target->season])
            ->where('updated_at', '<=', $capturedAt)->where('updated_at', '<', $cutoffAt)
            ->where('created_at', '<=', $capturedAt)->where('created_at', '<', $cutoffAt)
            ->where(fn ($query) => $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->with('sportEvent')->orderByDesc('game_date')->orderByDesc('game_time')->orderByDesc('id')->get()
            ->filter(function ($game) use ($capturedAt, $cutoffAt) {
                $starts = $game->sportEvent?->starts_at ?? ($game->game_date ? CarbonImmutable::parse(
                    $game->game_date->format('Y-m-d').' '.($game->game_time ?: '00:00:00'), config('app.timezone')) : null);

                return $starts && $starts->lt($capturedAt) && $starts->lt($cutoffAt)
                    && is_numeric($game->home_score) && is_numeric($game->away_score);
            })->take(10)->values();
        $stats = TeamStat::query()->where('team_id', $teamId)->whereIn('game_id', $games->pluck('id'))
            ->where('updated_at', '<=', $capturedAt)->where('updated_at', '<', $cutoffAt)
            ->where('created_at', '<=', $capturedAt)->where('created_at', '<', $cutoffAt)
            ->orderByDesc('updated_at')->orderByDesc('id')->get()->unique('game_id')->keyBy('game_id');
        $pace = $quarters = $observed = [];
        foreach ($games as $game) {
            $opponentId = (int) $game->home_team_id === $teamId ? $game->away_team_id : $game->home_team_id;
            $stat = $stats->get($game->id);
            if ($stat && is_numeric($stat->passing_attempts) && is_numeric($stat->rushing_attempts)
                && $stat->passing_attempts >= 0 && $stat->rushing_attempts >= 0
                && $stat->passing_attempts + $stat->rushing_attempts > 0) {
                $pace[] = ['game_id' => $game->id, 'opponent_team_id' => $opponentId, 'stat_id' => $stat->id,
                    'attempts' => (int) $stat->passing_attempts + (int) $stat->rushing_attempts,
                    'observed_at' => $stat->updated_at->toIso8601String()];
                $observed[] = $stat->updated_at->toImmutable();
            }
            $home = $this->regulationQuarters($game->home_linescores);
            $away = $this->regulationQuarters($game->away_linescores);
            if ($home !== null && $away !== null && array_sum($home) <= $game->home_score && array_sum($away) <= $game->away_score) {
                [$scored, $allowed] = (int) $game->home_team_id === $teamId ? [$home, $away] : [$away, $home];
                $quarters[] = ['game_id' => $game->id, 'opponent_team_id' => $opponentId, 'entering_fourth_margin' => array_sum(array_slice($scored, 0, 3)) - array_sum(array_slice($allowed, 0, 3)),
                    'fourth_points_scored' => $scored[3], 'fourth_points_allowed' => $allowed[3],
                    'fourth_margin' => $scored[3] - $allowed[3], 'observed_at' => $game->updated_at->toIso8601String()];
                $observed[] = $game->updated_at->toImmutable();
            }
        }
        $leads = array_values(array_filter($quarters, fn ($row) => $row['entering_fourth_margin'] >= 20));
        $average = fn (array $rows, string $field) => $rows ? round(array_sum(array_column($rows, $field)) / count($rows), 3) : null;

        return ['source' => 'stored_pregame_team_stats_and_regulation_linescores', 'applied_to_prediction' => false, 'opponent_adjustment' => 'none_raw_descriptive_samples',
            'window' => 'last_10_completed_games_current_and_previous_season_observed_before_capture',
            'captured_at' => $capturedAt->toIso8601String(), 'latest_source_observed_at' => $observed ? max($observed)->toIso8601String() : null,
            'eligible_games' => $games->count(), 'game_ids' => $games->pluck('id')->all(),
            'pace' => ['status' => $pace ? 'available_descriptive_proxy' : 'missing_frozen_evidence',
                'definition' => 'passing_attempts + rushing_attempts per game; not actual snap count, tempo, or seconds per play; may include overtime',
                'sample_games' => count($pace), 'plays_proxy_per_game' => $average($pace, 'attempts'), 'sources' => $pace],
            'late_game' => ['status' => $quarters ? 'available_descriptive_regulation_fourth_quarter' : 'missing_frozen_evidence',
                'definition' => 'Regulation quarter 4 scoring; not garbage-time labeling or proof of backdoor cover risk',
                'sample_games' => count($quarters), 'fourth_quarter_margin' => $average($quarters, 'fourth_margin'),
                'fourth_quarter_points_allowed' => $average($quarters, 'fourth_points_allowed'),
                'entered_fourth_leading_20_plus_games' => count($leads),
                'fourth_margin_when_leading_20_plus' => $average($leads, 'fourth_margin'), 'sources' => $quarters]];
    }

    private function regulationQuarters(mixed $lines): ?array
    {
        if (! is_array($lines) || ! array_is_list($lines) || count($lines) < 4) {
            return null;
        }
        $values = [];
        foreach ($lines as $index => $line) {
            $period = is_array($line) ? ($line['period'] ?? $index + 1) : $index + 1;
            $value = is_array($line) ? ($line['value'] ?? null) : $line;
            if (! is_numeric($period) || (int) $period < 1 || (int) $period > 4) {
                continue;
            }
            if (! is_numeric($value) || $value < 0 || (float) (int) $value !== (float) $value || isset($values[(int) $period])) {
                return null;
            }
            $values[(int) $period] = (int) $value;
        }
        ksort($values);

        return array_keys($values) === [1, 2, 3, 4] ? array_values($values) : null;
    }
}
