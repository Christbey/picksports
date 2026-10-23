<?php

namespace App\Services\BettingRecommendations;

use App\Models\NFL\PlayerProp;
use App\Services\Sports\SportsDateWindowService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Historical absences require a report observed before that game's kickoff. */
class NflPropParticipation
{
    public function forProps(Collection $props, Collection $statsByPlayer): array
    {
        $teamIds = $props->pluck('player.team_id')->filter()->unique();
        if ($teamIds->isEmpty()) {
            return [];
        }
        $dates = app(SportsDateWindowService::class);
        $games = DB::table('nfl_games')->whereIn('season', $props->pluck('game.season')->filter()->unique())
            ->where('status', 'STATUS_FINAL')->whereIn('season_type', ['2', 'regular'])
            ->where('game_date', '<=', $props->pluck('game.game_date')->max())
            ->where(fn ($q) => $q->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
            ->get(['id', 'season', 'game_date', 'game_time', 'home_team_id', 'away_team_id', 'home_qb_name', 'away_qb_name']);
        if ($games->isEmpty()) {
            return [];
        }
        $reports = DB::table('nfl_player_injury_snapshots')->whereIn('team_id', $teamIds)
            ->where('observed_at', '>=', Carbon::parse($games->min('game_date'))->subDays(7))
            ->where('observed_at', '<=', $props->pluck('game.game_date')->max())
            ->orderByDesc('observed_at')->orderByDesc('id')->get(['id', 'team_id', 'observed_at', 'source_updated_at']);
        $entries = $reports->isEmpty() ? collect() : DB::table('nfl_player_injury_snapshot_entries as entries')
            ->join('nfl_player_injury_snapshots as reports', 'reports.id', '=', 'entries.snapshot_id')
            ->whereIn('reports.team_id', $teamIds)
            ->whereBetween('reports.observed_at', [$reports->min('observed_at'), $reports->max('observed_at')])
            ->whereIn('entries.player_id', $props->pluck('player_id')->filter()->unique())
            ->get(['entries.snapshot_id', 'entries.player_id', 'entries.status', 'entries.observed_at', 'entries.source_updated_at'])->groupBy('snapshot_id');
        $reports->each(function ($report) {
            $report->observed_instant = CarbonImmutable::parse($report->observed_at, config('app.timezone'));
        });
        $reportsByTeam = $reports->groupBy('team_id');
        $reportCache = [];

        return $props->mapWithKeys(function (PlayerProp $prop) use ($dates, $games, $reportsByTeam, $entries, $statsByPlayer, &$reportCache) {
            $target = $prop->game;
            $kickoff = $target ? $dates->gameDateTimeUtc($target->game_date, $target->game_time) : null;
            $team = $prop->player?->team_id;
            $teamGames = $games->filter(function ($game) use ($dates, $kickoff, $target, $team) {
                $played = $dates->gameDateTimeUtc($game->game_date, $game->game_time);

                return $team && $kickoff && $played && $played->lt($kickoff)
                    && (int) $game->id !== (int) $target->id && (int) $game->season === (int) $target->season
                    && in_array((int) $team, [(int) $game->home_team_id, (int) $game->away_team_id], true);
            });
            $rows = collect($statsByPlayer->get($prop->player_id, []))->keyBy('game_id');
            $recorded = $unavailable = $unknown = $otherStarter = [];
            foreach ($teamGames as $game) {
                if ($rows->has($game->id) && (int) $rows[$game->id]->team_id === (int) $team) {
                    $recorded[] = $game->id;

                    continue;
                }
                $played = $dates->gameDateTimeUtc($game->game_date, $game->game_time);
                // Use the latest FULL report, so a resolved entry cannot linger from an older report.
                $key = $team.':'.$game->id;
                if (! array_key_exists($key, $reportCache)) {
                    $reportCache[$key] = collect($reportsByTeam->get($team, []))->first(fn ($r) => $r->observed_instant->lte($played)
                        && $r->observed_instant->gte($played->subDays(7)));
                }
                $report = $reportCache[$key];
                $out = $report && (! $report->source_updated_at || CarbonImmutable::parse($report->source_updated_at, config('app.timezone'))->lte($played))
                    && collect($entries->get($report->id, []))->contains(fn ($entry) => (int) $entry->player_id === (int) $prop->player_id
                        && $entry->observed_at && CarbonImmutable::parse($entry->observed_at, config('app.timezone'))->lte($report->observed_instant)
                        && (! $entry->source_updated_at || CarbonImmutable::parse($entry->source_updated_at, config('app.timezone'))->lte($played))
                        && in_array(strtolower(trim((string) $entry->status)), ['out', 'injured reserve', 'ir', 'pup', 'reserve/pup', 'suspended'], true));
                if ($out) {
                    $unavailable[] = $game->id;
                } else {
                    $unknown[] = $game->id;
                    $starter = (int) $game->home_team_id === (int) $team ? $game->home_qb_name : $game->away_qb_name;
                    if ($prop->player?->position === 'QB' && $starter && strcasecmp(trim($starter), trim($prop->player->full_name)) !== 0) {
                        $otherStarter[] = $game->id;
                    }
                }
            }

            return [$prop->id => [
                'basis' => 'current_team_completed_regular_season_games',
                'team_games' => $teamGames->count(),
                'recorded_games' => count($recorded),
                'confirmed_unavailable_games' => count($unavailable),
                'unexplained_absence_games' => count($unknown),
                'other_qb_started_games' => count($otherStarter),
                'confirmed_unavailable_game_ids' => $unavailable,
                'unexplained_absence_game_ids' => $unknown,
                'absence_policy' => 'excluded_not_zero; unknown_absence_is_not_confirmed_missing_data',
            ]];
        })->all();
    }
}
