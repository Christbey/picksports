<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\RepairTournamentStructure;
use App\Support\BasketballSeason;
use Illuminate\Console\Command;

class RepairTournamentStructureCommand extends Command
{
    protected $signature = 'cbb:repair-tournament-structure
                            {--season= : Restrict to a season}';

    protected $description = 'Create or update placeholder CBB NCAA tournament games for missing structural slots';

    public function handle(RepairTournamentStructure $repairTournamentStructure): int
    {
        $value = $this->input->hasParameterOption('--season', true)
            ? $this->option('season') : (config('cbb.season.default') ?? BasketballSeason::forDate());
        $season = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($season === false) {
            $this->error('The season option requires a positive integer.');

            return self::FAILURE;
        }
        $count = $repairTournamentStructure->execute($season);

        $this->info("Tournament structure repair complete for {$season}. Repaired {$count} slot(s).");

        return self::SUCCESS;
    }
}
