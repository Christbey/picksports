<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\RepairTournamentStructure;
use Illuminate\Console\Command;

class RepairTournamentStructureCommand extends Command
{
    protected $signature = 'cbb:repair-tournament-structure
                            {--season= : Restrict to a season}';

    protected $description = 'Create or update placeholder CBB NCAA tournament games for missing structural slots';

    public function handle(RepairTournamentStructure $repairTournamentStructure): int
    {
        $season = (int) ($this->option('season') ?: config('cbb.season.default'));
        $count = $repairTournamentStructure->execute($season);

        $this->info("Tournament structure repair complete for {$season}. Repaired {$count} slot(s).");

        return self::SUCCESS;
    }
}
