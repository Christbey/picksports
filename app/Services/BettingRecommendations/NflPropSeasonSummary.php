<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\PlayerProp;
use App\Services\NFL\TeamPlayoffForecastService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only season evidence, separate from the historical model inputs. */
class NflPropSeasonSummary
{
    private const FIELDS = [
        'player_pass_tds' => ['passing_touchdowns'],
        'player_pass_yds' => ['passing_yards'],
        'player_pass_completions' => ['passing_completions'],
        'player_pass_attempts' => ['passing_attempts'],
        'player_pass_interceptions' => ['interceptions_thrown'],
        'player_rush_yds' => ['rushing_yards'],
        'player_rush_attempts' => ['rushing_attempts'],
        'player_receptions' => ['receptions'],
        'player_reception_yds' => ['receiving_yards'],
        'player_anytime_td' => ['rushing_touchdowns', 'receiving_touchdowns'],
    ];

    /** @param Collection<int, PlayerProp> $props */
    public function forProps(Collection $props): array
    {
        if ($props->isEmpty()) {
            return [];
        }

        $props->loadMissing(['game', 'player']);
        $teams = DB::table('nfl_teams')->get(['id', 'abbreviation', 'conference'])->keyBy('id');
        $rows = DB::table('nfl_player_stats as stats')
            ->join('nfl_games as games', 'games.id', '=', 'stats.game_id')
            ->whereIn('stats.player_id', $props->pluck('player_id')->filter()->unique())
            ->where('games.season', '<=', $props->pluck('game.season')->max())
            ->where('games.game_date', '<=', $props->pluck('game.game_date')->max())
            ->where('games.status', 'STATUS_FINAL')
            ->whereIn('games.season_type', ['2', 'regular'])
            ->select([
                'stats.player_id', 'stats.game_id', 'stats.team_id',
                ...collect(self::FIELDS)->flatten()->unique()->map(fn ($field) => 'stats.'.$field)->all(),
                'games.season', 'games.game_date', 'games.game_time', 'games.home_team_id', 'games.away_team_id',
            ])
            ->get()->groupBy('player_id');
        $dates = app(SportsDateWindowService::class);

        return $props->mapWithKeys(function (PlayerProp $prop) use ($rows, $dates, $teams): array {
            $game = $prop->game;
            $kickoff = $game ? $dates->gameDateTimeUtc($game->game_date, $game->game_time) : null;
            $fields = self::FIELDS[$prop->market] ?? [];
            $history = collect($rows->get($prop->player_id, []))->filter(function ($row) use ($game, $kickoff, $dates): bool {
                $playedAt = $dates->gameDateTimeUtc($row->game_date, $row->game_time);

                return $game && $kickoff && $playedAt
                    && (int) $row->season <= (int) $game->season
                    && (int) $row->game_id !== (int) $game->id
                    && $playedAt->lt($kickoff);
            });
            $eligible = $history->where('season', $game?->season);
            $playerTeam = $prop->player?->team_id;
            $opponentId = $game && $playerTeam
                ? ((int) $playerTeam === (int) $game->home_team_id ? $game->away_team_id
                    : ((int) $playerTeam === (int) $game->away_team_id ? $game->home_team_id : null))
                : null;
            $opponent = $teams->get($opponentId);
            $conference = $this->teamConference($opponent);
            // Use the player's team AT THAT GAME, not their current team after a trade.
            $opposedTeam = fn ($row) => (int) $row->team_id === (int) $row->home_team_id ? $row->away_team_id
                : ((int) $row->team_id === (int) $row->away_team_id ? $row->home_team_id : null);
            $records = [
                'season' => $this->record($eligible, $fields, $prop),
                'last_season' => $this->record($history->where('season', (int) $game?->season - 1), $fields, $prop),
                'all_time' => $this->record($history, $fields, $prop),
                'vs_opponent' => $opponentId ? $this->record($history->filter(fn ($row) => (int) $opposedTeam($row) === (int) $opponentId), $fields, $prop) : null,
                'vs_conference' => $conference ? $this->record($history->filter(fn ($row) => $this->teamConference($teams->get($opposedTeam($row))) === $conference), $fields, $prop) : null,
            ];
            // Missing statistics are unknown, not zero performances.
            $values = $this->values($eligible, $fields);

            return [$prop->id => [
                'season' => $game?->season === null ? null : (int) $game->season,
                'season_type' => 'regular',
                'average' => $values->isEmpty() ? null : round($values->avg(), 1),
                'games' => $values->count(),
                'missing_stat_games' => $eligible->count() - $values->count(),
                'cover_record' => $records['season'],
                'records' => $records,
                'opponent' => $opponent?->abbreviation,
                'opponent_conference' => $conference,
                'conference_basis' => 'current_team_conference_membership',
            ]];
        })->all();
    }

    private function values(\Illuminate\Support\Collection $rows, array $fields): \Illuminate\Support\Collection
    {
        return $rows->filter(fn ($row) => $fields !== [] && collect($fields)->every(fn ($field) => is_numeric($row->{$field})))
            ->map(fn ($row) => collect($fields)->sum(fn ($field) => (float) $row->{$field}))->values();
    }

    private function record(\Illuminate\Support\Collection $rows, array $fields, PlayerProp $prop): ?array
    {
        $values = $this->values($rows, $fields);
        $side = ucfirst(strtolower((string) $prop->recommended_side));
        if ($values->isEmpty() || ! is_numeric($prop->line) || ! in_array($side, ['Over', 'Under'], true)) {
            return null;
        }
        $over = $values->filter(fn ($value) => $value > (float) $prop->line)->count();
        $under = $values->filter(fn ($value) => $value < (float) $prop->line)->count();
        $pushes = $values->count() - $over - $under;
        $wins = $side === 'Under' ? $under : $over;
        $losses = $side === 'Under' ? $over : $under;

        return [
            'games' => $values->count(), 'over' => $over, 'under' => $under, 'pushes' => $pushes,
            'hits' => $over, 'recommendation' => $side, 'wins' => $wins, 'losses' => $losses,
            'win_rate' => $wins + $losses > 0 ? round(100 * $wins / ($wins + $losses), 1) : null,
            'record' => "{$over}-{$under}".($pushes ? "-{$pushes}" : ''),
            'recommendation_record' => "{$wins}-{$losses}".($pushes ? "-{$pushes}" : ''),
            'missing_stat_games' => $rows->count() - $values->count(),
        ];
    }

    private function conference(?string $value): ?string
    {
        return match (strtoupper(trim($value ?? ''))) {
            'AFC', 'AMERICAN FOOTBALL CONFERENCE' => 'AFC',
            'NFC', 'NATIONAL FOOTBALL CONFERENCE' => 'NFC',
            default => null,
        };
    }

    private function teamConference(?object $team): ?string
    {
        return $this->conference($team?->conference)
            ?? TeamPlayoffForecastService::conferenceForAbbreviation($team?->abbreviation ?? '');
    }
}
