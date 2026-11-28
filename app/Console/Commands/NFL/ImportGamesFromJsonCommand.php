<?php

namespace App\Console\Commands\NFL;

use Illuminate\Console\Command;

class ImportGamesFromJsonCommand extends Command
{
    protected $signature = 'nfl:import-games-from-json
                            {file? : Path to the JSON file (defaults to nfl_team_schedules.json in project root)}';

    protected $description = '[Retired] Unsupported legacy NFL data import';

    public function handle(): int
    {
        $this->error('nfl:import-games-from-json is retired. It performs no database or file operations. Use the reviewed native restore workflow in docs/operations/database-access.md for approved database recovery, or the supported espn:sync-nfl-games and espn:sync-nfl-game-details acquisition pipeline when an explicit provider refresh is authorized.');

        return Command::FAILURE;
    }
}
