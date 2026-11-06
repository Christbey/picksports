<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use App\Support\Sports\CbbSeasonTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CbbDataQuality
{
    public function realGames(Builder $query): Builder
    {
        return $query->whereNotNull('espn_event_id')
            ->where('espn_event_id', 'not like', 'placeholder:%')
            ->whereHas('homeTeam', fn (Builder $team) => $team->where('espn_id', '>', 0)->whereNotIn('abbreviation', ['TBD', 'TBD2', 'WFF', 'FF']))
            ->whereHas('awayTeam', fn (Builder $team) => $team->where('espn_id', '>', 0)->whereNotIn('abbreviation', ['TBD', 'TBD2', 'WFF', 'FF']));
    }

    public function reconciledGames(Builder $query): Builder
    {
        return $this->realGames($query)->where('status', 'STATUS_FINAL')
            ->whereIn('season_type', CbbSeasonTypes::analytics())
            ->whereDate('game_date', '<=', now('UTC')->toDateString())
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->has('teamStats', '=', 2)
            ->whereHas('teamStats', fn (Builder $stats) => $stats->whereColumn('team_id', 'cbb_games.home_team_id')
                ->whereColumn('points', 'cbb_games.home_score')->whereRaw($this->possessionExpression().' > 0'))
            ->whereHas('teamStats', fn (Builder $stats) => $stats->whereColumn('team_id', 'cbb_games.away_team_id')
                ->whereColumn('points', 'cbb_games.away_score')->whereRaw($this->possessionExpression().' > 0'));
    }

    private function possessionExpression(): string
    {
        $coefficient = (float) config('cbb.metrics.possession_coefficient', 0.4);

        return "COALESCE(possessions, field_goals_attempted - offensive_rebounds + turnovers + {$coefficient} * free_throws_attempted)";
    }

    /** @return list<string> */
    public function boxScoreIssues(Game $game, Collection $stats): array
    {
        $issues = [];
        if ($stats->count() !== 2 || $stats->pluck('team_id')->unique()->count() !== 2) {
            $issues[] = 'two_team_box_scores_required';
        }
        foreach ([$game->home_team_id => $game->home_score, $game->away_team_id => $game->away_score] as $teamId => $score) {
            $stat = $stats->firstWhere('team_id', $teamId);
            if ($stat === null) {
                $issues[] = 'missing_team_box_score';

                continue;
            }
            if ($score === null || $stat->points === null || (int) $stat->points !== (int) $score) {
                $issues[] = 'box_score_points_do_not_match_final';
            }
            $possessions = $stat->possessions ?? ($stat->field_goals_attempted - $stat->offensive_rebounds + $stat->turnovers + (float) config('cbb.metrics.possession_coefficient', 0.4) * $stat->free_throws_attempted);
            if (! is_numeric($possessions) || (float) $possessions <= 0) {
                $issues[] = 'invalid_possessions';
            }
            foreach (['field_goals', 'free_throws', 'three_point'] as $shot) {
                $made = $shot === 'three_point' ? 'three_point_made' : $shot.'_made';
                $attempted = $shot === 'three_point' ? 'three_point_attempted' : $shot.'_attempted';
                if ($stat->{$made} === null || $stat->{$attempted} === null || $stat->{$made} < 0 || $stat->{$made} > $stat->{$attempted}) {
                    $issues[] = 'invalid_shooting_counts';
                }
            }
            if ($stat->field_goals_made !== null && $stat->three_point_made !== null && $stat->free_throws_made !== null
                && 2 * $stat->field_goals_made + $stat->three_point_made + $stat->free_throws_made !== (int) $stat->points) {
                $issues[] = 'shooting_points_do_not_reconcile';
            }
        }

        return array_values(array_unique($issues));
    }
}
