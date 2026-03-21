<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\RepairTournamentStructure;
use App\Actions\ESPN\CBB\SyncTeamSchedule;
use App\Models\CBB\Team;
use Illuminate\Console\Command;

class SyncTournamentStructureCommand extends Command
{
    protected $signature = 'cbb:sync-tournament-structure
                            {--season= : Restrict to a season}
                            {--team-limit= : Limit number of teams to sync}
                            {--skip-backfill : Skip tournament metadata backfill}
                            {--skip-repair : Skip tournament structure repair}
                            {--skip-audit : Skip tournament structure audit}';

    protected $description = 'Synchronously refresh CBB tournament structure coverage from ESPN team schedules';

    public function handle(SyncTeamSchedule $syncTeamSchedule, RepairTournamentStructure $repairTournamentStructure): int
    {
        $season = (int) ($this->option('season') ?: config('cbb.season.default'));
        $teams = Team::query()
            ->whereNotNull('espn_id')
            ->orderBy('id')
            ->when($this->option('team-limit'), fn ($query, $limit) => $query->limit((int) $limit))
            ->get();

        if ($teams->isEmpty()) {
            $this->warn('No CBB teams found to sync.');

            return self::SUCCESS;
        }

        $this->info("Syncing tournament structure coverage from team schedules for {$teams->count()} teams...");
        $bar = $this->output->createProgressBar($teams->count());
        $bar->start();

        $syncedGames = 0;
        foreach ($teams as $team) {
            $syncedGames += $syncTeamSchedule->execute((string) $team->espn_id, $season);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Schedule sync complete. Synced {$syncedGames} game records.");

        if (! $this->option('skip-backfill')) {
            $this->call('cbb:backfill-tournament-metadata', ['--season' => $season]);
        }

        if (! $this->option('skip-repair')) {
            $repaired = $repairTournamentStructure->execute($season);
            $this->info("Tournament structure repair complete. Repaired {$repaired} slot(s).");
        }

        if ($this->option('skip-audit')) {
            return self::SUCCESS;
        }

        return $this->call('cbb:audit-tournament-structure', ['--season' => $season]);
    }
}
