<?php

namespace App\Services\NFL;

use App\Models\NFL\PlayerStat;
use App\Services\BettingRecommendations\NflPropProjectionBacktest;
use App\Services\BettingRecommendations\NflPropRoleHistory;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Collection;

class NflPropShadowFeatures extends NflPropProjectionBacktest
{
    public const VERSION = 'nfl-prop-benchmark-v1';

    public function forProps(Collection $props): array
    {
        if ($props->isEmpty()) {
            return [];
        }
        $dates = app(SportsDateWindowService::class);
        $seasons = $props->pluck('game.season')->filter()->flatMap(fn ($season) => [$season - 1, $season])->unique();
        $byPlayer = PlayerStat::query()->whereIn('player_id', $props->pluck('player_id')->filter()->unique())
            ->whereHas('game', fn ($query) => $query->whereIn('season', $seasons)->where('status', 'STATUS_FINAL')->whereIn('season_type', ['2', 'regular']))
            ->with('game:id,season,status,season_type,game_date,game_time,home_team_id,away_team_id,home_qb_name,away_qb_name')
            ->get(['id', 'game_id', 'player_id', 'team_id', 'passing_attempts', 'passing_yards', 'passing_completions',
                'passing_touchdowns', 'interceptions_thrown', 'rushing_attempts', 'rushing_yards', 'rushing_touchdowns',
                'receiving_targets', 'receptions', 'receiving_yards', 'receiving_touchdowns'])->groupBy('player_id');
        $samples = [];
        foreach ($props as $prop) {
            $player = $prop->player;
            $game = $prop->game;
            $field = $this->getStatFieldForMarket($prop->market);
            if (! $player || ! $game || ! $field || ! in_array((int) $player->team_id, [(int) $game->home_team_id, (int) $game->away_team_id], true)) {
                continue;
            }
            $team = (int) $player->team_id;
            $isHome = $team === (int) $game->home_team_id;
            $quarterback = $isHome ? $game->home_qb_name : $game->away_qb_name;
            if ($player->position === 'QB' && filled($quarterback) && strcasecmp(trim($quarterback), trim($player->full_name)) !== 0) {
                continue;
            }
            $prior = collect($byPlayer->get($player->id, []))->filter(function ($row) use ($dates, $game, $team, $field): bool {
                $kickoff = $dates->gameDateTimeUtc($row->game->game_date, $row->game->game_time);

                return (int) $row->team_id === $team && $kickoff && $kickoff->addDay()->lt(now())
                    && in_array((int) $row->game->season, [(int) $game->season - 1, (int) $game->season], true)
                    && $row->game->game_date->toDateString() < $game->game_date->toDateString()
                    && $this->statValue($row, $field) !== null;
            })->sortByDesc(fn ($row) => [$row->game->game_date->toDateString(), $row->id])->values();
            $prior = app(NflPropRoleHistory::class)->select($prior, $player)['history'];
            if ($prior->count() < 3) {
                continue;
            }
            $current = $prior->filter(fn ($row) => (int) $row->game->season === (int) $game->season);
            $history = ($current->isNotEmpty() ? $current : $prior)->values();
            $opponent = $isHome ? (int) $game->away_team_id : (int) $game->home_team_id;
            $samples[$prop->id] = [
                'features' => $this->benchmarkFeatures($history, $field, $team, $opponent, $isHome, (int) $game->season),
                'comparators' => ['recent5_mean' => $this->averageStatValue($history->take(5), $field),
                    'legacy_neutral' => $this->legacyBaseline($history, $prop->market, $field, $team, $opponent, $isHome)],
                'player_id' => (int) $player->id, 'team_id' => $team,
                'history_stat_ids' => $history->pluck('id')->all(),
                'features_observed_at' => now()->toIso8601String(),
            ];
        }

        return $samples;
    }
}
