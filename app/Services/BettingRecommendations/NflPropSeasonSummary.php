<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\PlayerProp;
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

        $props->loadMissing('game');
        $rows = DB::table('nfl_player_stats as stats')
            ->join('nfl_games as games', 'games.id', '=', 'stats.game_id')
            ->whereIn('stats.player_id', $props->pluck('player_id')->filter()->unique())
            ->whereIn('games.season', $props->pluck('game.season')->filter()->unique())
            ->where('games.status', 'STATUS_FINAL')
            ->whereIn('games.season_type', ['2', 'regular'])
            ->select(['stats.*', 'games.season', 'games.game_date', 'games.game_time'])
            ->get()->groupBy('player_id');
        $dates = app(SportsDateWindowService::class);

        return $props->mapWithKeys(function (PlayerProp $prop) use ($rows, $dates): array {
            $game = $prop->game;
            $kickoff = $game ? $dates->gameDateTimeUtc($game->game_date, $game->game_time) : null;
            $fields = self::FIELDS[$prop->market] ?? [];
            $eligible = collect($rows->get($prop->player_id, []))->filter(function ($row) use ($game, $kickoff, $dates): bool {
                $playedAt = $dates->gameDateTimeUtc($row->game_date, $row->game_time);

                return $game && $kickoff && $playedAt
                    && (int) $row->season === (int) $game->season
                    && (int) $row->game_id !== (int) $game->id
                    && $playedAt->lt($kickoff);
            });
            // Missing statistics are unknown, not zero performances.
            $values = $eligible->filter(fn ($row) => $fields !== [] && collect($fields)->every(fn ($field) => is_numeric($row->{$field})))
                ->map(fn ($row) => collect($fields)->sum(fn ($field) => (float) $row->{$field}))->values();
            $over = $values->filter(fn ($value) => $value > (float) $prop->line)->count();
            $under = $values->filter(fn ($value) => $value < (float) $prop->line)->count();
            $pushes = $values->count() - $over - $under;
            $side = ucfirst(strtolower((string) $prop->recommended_side));
            $wins = $side === 'Under' ? $under : $over;
            $losses = $side === 'Under' ? $over : $under;
            $record = $values->isNotEmpty() && is_numeric($prop->line) && in_array($side, ['Over', 'Under'], true) ? [
                'games' => $values->count(), 'over' => $over, 'under' => $under, 'pushes' => $pushes,
                'hits' => $over, 'recommendation' => $side, 'wins' => $wins, 'losses' => $losses,
                'win_rate' => $wins + $losses > 0 ? round(100 * $wins / ($wins + $losses), 1) : null,
                'record' => "{$over}-{$under}".($pushes ? "-{$pushes}" : ''),
                'recommendation_record' => "{$wins}-{$losses}".($pushes ? "-{$pushes}" : ''),
            ] : null;

            return [$prop->id => [
                'season' => $game?->season === null ? null : (int) $game->season,
                'season_type' => 'regular',
                'average' => $values->isEmpty() ? null : round($values->avg(), 1),
                'games' => $values->count(),
                'missing_stat_games' => $eligible->count() - $values->count(),
                'cover_record' => $record,
            ]];
        })->all();
    }
}
