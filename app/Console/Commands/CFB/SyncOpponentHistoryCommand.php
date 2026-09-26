<?php

namespace App\Console\Commands\CFB;

use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Jobs\ESPN\CFB\FetchGameDetails;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use Illuminate\Console\Command;

class SyncOpponentHistoryCommand extends Command
{
    protected $signature = 'cfb:sync-opponent-history {--season=} {--days=14} {--limit=40} {--summary-limit=200}';

    protected $description = 'Refresh current and previous season schedules for upcoming opponents outside the FBS metrics pipeline';

    public function handle(SyncGamesFromSchedule $sync): int
    {
        $season = (int) ($this->option('season') ?: (now()->month <= 2 ? now()->year - 1 : now()->year));
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $summaryLimit = filter_var($this->option('summary-limit'), FILTER_VALIDATE_INT);
        if ($season < 2000 || $season > now()->year + 1 || $days === false || $days < 1 || $days > 60 || $limit === false || $limit < 1 || $limit > 100 || $summaryLimit === false || $summaryLimit < 1 || $summaryLimit > 1000) {
            $this->error('Use a valid season, 1–60 days and 1–100 teams and 1–1000 game summaries.');

            return self::FAILURE;
        }
        $games = Game::where('season', $season)->whereDate('game_date', '>=', now()->toDateString())
            ->whereDate('game_date', '<=', now()->addDays($days)->toDateString())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->get(['home_team_id', 'away_team_id', 'game_date']);
        $ids = $games->flatMap(fn ($g) => [$g->home_team_id, $g->away_team_id])->unique();
        // A fixed ID limit repeatedly starved later-ID FCS opponents of FBS teams.
        $fbs = TeamSeasonAffiliation::where('season', $season)->where('subdivision', 'FBS')->pluck('team_id')->flip();
        $priority = [];
        foreach ($games as $game) {
            foreach ([[$game->home_team_id, $game->away_team_id], [$game->away_team_id, $game->home_team_id]] as [$id, $opponent]) {
                $rank = [$fbs->has($opponent) ? 0 : 1, $game->game_date->toDateString(), $id];
                if (! isset($priority[$id]) || $rank < $priority[$id]) {
                    $priority[$id] = $rank;
                }
            }
        }
        $teams = Team::whereIn('id', $ids)->whereNotNull('espn_id')
            ->whereHas('seasonAffiliations', fn ($q) => $q->where('season', $season)->where('subdivision', '!=', 'FBS'))
            ->get()->sort(fn ($a, $b) => $priority[$a->id] <=> $priority[$b->id])->take($limit);
        $failed = 0;
        foreach ($teams as $team) {
            foreach ([$season - 1, $season] as $year) {
                try {
                    $count = $sync->execute((string) $team->espn_id, $year);
                    if ($count === 0) {
                        $failed++;
                    }
                    $this->line(json_encode(['team_id' => $team->id, 'team' => $team->school, 'season' => $year, 'games_synced' => $count]));
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("Opponent history failed for team {$team->id}, season {$year}: ".class_basename($e));
                }
            }
        }
        // Schedule updates intentionally cannot finalize existing games. Fetch authoritative
        // summaries separately; their normal importer validates and archives the data.
        $teamIds = $teams->modelKeys();
        $history = Game::whereBetween('season', [$season - 1, $season])
            ->whereDate('game_date', '<', now()->toDateString())->whereNotNull('espn_event_id')
            ->where(fn ($q) => $q->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_FINAL'])
            ->where(fn ($q) => $q->where('status', '!=', 'STATUS_FINAL')
                ->orWhereNull('home_score')->orWhereNull('away_score')
                ->orWhereNotIn('id', GameDataCheck::where('component', 'boxscore')->where('state', 'complete')
                    ->whereNotNull('accepted_at')->select('game_id')))
            ->orderByDesc('season')->orderByDesc('game_date')->orderBy('id')->limit($summaryLimit)->get();
        foreach ($history as $game) {
            FetchGameDetails::dispatch((string) $game->espn_event_id)->onQueue('sync');
        }
        $this->info(json_encode(['teams' => $teams->count(), 'failed' => $failed,
            'summary_requests' => $history->count(), 'summary_queue' => 'sync']));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
