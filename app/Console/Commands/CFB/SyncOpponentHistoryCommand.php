<?php

namespace App\Console\Commands\CFB;

use App\Actions\ESPN\CFB\SyncGamesFromSchedule;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use Illuminate\Console\Command;

class SyncOpponentHistoryCommand extends Command
{
    protected $signature = 'cfb:sync-opponent-history {--season=} {--days=14} {--limit=40}';

    protected $description = 'Refresh current and previous season schedules for upcoming opponents outside the FBS metrics pipeline';

    public function handle(SyncGamesFromSchedule $sync): int
    {
        $season = (int) ($this->option('season') ?: (now()->month <= 2 ? now()->year - 1 : now()->year));
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($season < 2000 || $season > now()->year + 1 || $days === false || $days < 1 || $days > 60 || $limit === false || $limit < 1 || $limit > 100) {
            $this->error('Use a valid season, 1–60 days and 1–100 teams.');

            return self::FAILURE;
        }
        $games = Game::where('season', $season)->whereDate('game_date', '>=', now()->toDateString())
            ->whereDate('game_date', '<=', now()->addDays($days)->toDateString())->get(['home_team_id', 'away_team_id']);
        $ids = $games->flatMap(fn ($g) => [$g->home_team_id, $g->away_team_id])->unique();
        $teams = Team::whereIn('id', $ids)->whereNotNull('espn_id')
            ->whereHas('seasonAffiliations', fn ($q) => $q->where('season', $season)->where('subdivision', '!=', 'FBS'))
            ->orderBy('id')->limit($limit)->get();
        $failed = 0;
        foreach ($teams as $team) {
            foreach ([$season - 1, $season] as $year) {
                try {
                    $count = $sync->execute((string) $team->espn_id, $year);
                    $this->line(json_encode(['team_id' => $team->id, 'team' => $team->school, 'season' => $year, 'games_synced' => $count]));
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("Opponent history failed for team {$team->id}, season {$year}: ".class_basename($e));
                }
            }
        }
        $this->info(json_encode(['teams' => $teams->count(), 'failed' => $failed]));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
