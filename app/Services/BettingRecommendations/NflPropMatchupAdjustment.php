<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use Illuminate\Support\Collection;

class NflPropMatchupAdjustment
{
    private const MIN_DEFENSE_GAMES = 3;

    private const MIN_OTHER_OFFENSE_GAMES = 2;

    private const PRIOR_GAMES = 3;

    private array $datasets = [];

    private array $rankings = [];

    public function resolve(Game $game, Player $player, string $market, Collection $history): array
    {
        $field = match ($market) {
            'player_receptions' => 'receptions',
            'player_reception_yds' => 'receiving_yards',
            'player_rush_yds' => 'rushing_yards',
            'player_rush_attempts' => 'rushing_attempts',
            'player_pass_yds' => 'passing_yards',
            'player_pass_attempts' => 'passing_attempts',
            'player_pass_completions' => 'passing_completions',
            default => null,
        };
        $position = strtoupper((string) $player->position);
        $empty = ['version' => 'nfl-position-matchup-v1', 'factor' => 1.0, 'status' => 'unavailable', 'field' => $field, 'position' => $position];
        $positions = match ($market) {
            'player_receptions', 'player_reception_yds' => ['WR', 'TE', 'RB'],
            'player_rush_yds', 'player_rush_attempts' => ['RB', 'QB'],
            default => ['QB'],
        };
        if ($field === null || ! in_array($position, $positions, true)) {
            return [...$empty, 'reason' => 'unsupported_market_or_position'];
        }

        $rows = $this->dataset($game, $position, $field);
        $league = $rows->avg('value');
        if ($league === null || $league <= 0) {
            return [...$empty, 'reason' => 'missing_position_coverage'];
        }
        $key = implode(':', [$game->season, $game->game_date->toIso8601String(), $position, $field]);
        $profiles = $this->rankings[$key] ??= $this->profiles($rows, $league);
        $opponentId = (int) ($game->home_team_id === $player->team_id ? $game->away_team_id : $game->home_team_id);
        $target = $profiles->get($opponentId);
        $sample = $history->filter(fn ($row) => $row->game !== null
            && (int) $row->game->season === (int) $game->season
            && in_array((string) $row->game->season_type, ['2', 'regular'], true)
            && $row->game->status === 'STATUS_FINAL'
            && $row->game->game_date->lt($game->game_date)
            && (int) $row->team_id === (int) $player->team_id
            && $row->{$field} !== null)->unique('game_id');
        $comparisons = $sample->map(function ($row) use ($profiles, $field) {
            $defenseId = (int) ($row->game->home_team_id === $row->team_id ? $row->game->away_team_id : $row->game->home_team_id);
            $profile = $profiles->get($defenseId);

            return [
                'game_id' => (int) $row->game_id,
                'opponent_team_id' => $defenseId,
                'player_value' => (float) $row->{$field},
                'opponent_rank' => $profile['rank'] ?? null,
                'opponent_allowed_average' => $profile['allowed_average'] ?? null,
                'opponent_adjusted_average' => $profile['adjusted_average'] ?? null,
                'comparison_average' => $profile['comparison_average'] ?? null,
            ];
        })->values();
        $covered = $comparisons->whereNotNull('comparison_average');
        $baseline = $covered->avg('comparison_average');
        $evidence = [
            ...$empty,
            'season' => (int) $game->season,
            'as_of' => $game->game_date->toIso8601String(),
            'rank_basis' => 'opponent_offense_adjusted_position_total_per_game; lower_is_stronger',
            'rank_scope' => 'covered_defenses_only',
            'ranked_teams' => $profiles->count(),
            'league_average' => round($league, 3),
            'opponent_team_id' => $opponentId,
            'opponent' => $target,
            'player_average' => $sample->avg($field),
            'historical_games' => $sample->count(),
            'covered_history_games' => $covered->count(),
            'average_opponent_rank' => $covered->avg('opponent_rank'),
            'average_opponent_allowed' => $covered->avg('opponent_allowed_average'),
            'average_opponent_adjusted' => $baseline,
            'comparisons' => $comparisons->all(),
            'limitation' => 'Position totals are not individual coverage assignments; adjustments are unvalidated and do not imply target redistribution.',
        ];
        if ($target === null || $covered->count() < self::MIN_DEFENSE_GAMES || $covered->count() !== $comparisons->count() || $baseline === null || $baseline <= 0) {
            return [...$evidence, 'reason' => 'insufficient_comparable_current_season_games'];
        }

        $ratio = $target['comparison_average'] / $baseline;

        return [...$evidence, 'status' => 'applied', 'reason' => null, 'raw_factor' => round($ratio, 4), 'factor' => round(max(0.85, min(1.15, $ratio)), 3)];
    }

    private function dataset(Game $game, string $position, string $field): Collection
    {
        $key = implode(':', [$game->season, $game->game_date->toIso8601String(), $position, $field]);
        if (isset($this->datasets[$key])) {
            return $this->datasets[$key];
        }
        $participation = str_starts_with($field, 'passing_') ? 'passing_attempts'
            : (str_starts_with($field, 'rushing_') ? 'rushing_attempts' : 'receiving_targets');
        $stats = PlayerStat::query()
            ->select(['id', 'game_id', 'player_id', 'team_id', $field, ...($participation !== $field ? [$participation] : [])])
            ->with(['game:id,home_team_id,away_team_id', 'player:id,position'])
            ->whereHas('player', fn ($q) => $q->where('position', $position))
            ->whereHas('game', fn ($q) => $q->where('season', $game->season)
                ->whereIn('season_type', ['2', 'regular'])->where('status', 'STATUS_FINAL')
                ->where('game_date', '<', $game->game_date))
            ->where(fn ($q) => $q->whereNotNull($field)->orWhereNotNull($participation))
            ->get();

        return $this->datasets[$key] = $stats->groupBy(fn ($row) => $row->game_id.':'.$row->team_id)
            ->filter(fn ($group) => ! $group->contains(fn ($row) => $row->{$field} === null))
            ->map(function ($group) use ($field) {
                $row = $group->first();
                $g = $row->game;
                if (! in_array((int) $row->team_id, [(int) $g->home_team_id, (int) $g->away_team_id], true)) {
                    return null;
                }

                return ['game_id' => (int) $row->game_id, 'offense' => (int) $row->team_id,
                    'defense' => (int) ($g->home_team_id === $row->team_id ? $g->away_team_id : $g->home_team_id),
                    'value' => (float) $group->sum($field)];
            })->filter()->values();
    }

    private function profiles(Collection $rows, float $league): Collection
    {
        $offenses = $rows->groupBy('offense');
        $profiles = $rows->groupBy('defense')->map(function ($allowed, $defenseId) use ($offenses, $league) {
            if ($allowed->count() < self::MIN_DEFENSE_GAMES) {
                return null;
            }
            $expected = $allowed->map(function ($row) use ($offenses, $defenseId) {
                $other = $offenses->get($row['offense'])->where('defense', '!=', $defenseId);

                return $other->count() >= self::MIN_OTHER_OFFENSE_GAMES ? $other->avg('value') : null;
            });
            if ($expected->contains(fn ($value) => $value === null) || $expected->avg() <= 0) {
                return null;
            }
            $average = $allowed->avg('value');
            $adjusted = $league * $average / $expected->avg();

            return [
                'games' => $allowed->count(),
                'game_ids' => $allowed->pluck('game_id')->all(),
                'allowed_average' => round($average, 3),
                'opposing_offense_average' => round($expected->avg(), 3),
                'adjusted_average' => round($adjusted, 3),
                'comparison_average' => round(($adjusted * $allowed->count() + $league * self::PRIOR_GAMES) / ($allowed->count() + self::PRIOR_GAMES), 3),
            ];
        })->filter();

        return $profiles->map(fn ($profile) => [...$profile, 'rank' => 1 + $profiles->filter(fn ($other) => $other['adjusted_average'] < $profile['adjusted_average'])->count()]);
    }
}
