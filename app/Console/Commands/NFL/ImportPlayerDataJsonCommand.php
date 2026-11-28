<?php

namespace App\Console\Commands\NFL;

use Illuminate\Console\Command;

class ImportPlayerDataJsonCommand extends Command
{
    protected $signature = 'nfl:import-player-data-json
                            {--player-data=/Users/bey/Downloads/nfl_player_data/nfl_player_data.json : Path to nfl_player_data.json}
                            {--player-stats=/Users/bey/Downloads/nfl_player_data/nfl_player_stats.json : Path to nfl_player_stats.json}
                            {--player-season-stats=/Users/bey/Downloads/nfl_player_data/nfl_player_season_stats.json : Path to nfl_player_season_stats.json}
                            {--plays=/Users/bey/Downloads/nfl_player_data/nfl_plays.json : Path to nfl_plays.json}
                            {--team-stats=/Users/bey/Downloads/nfl_player_data/nfl_team_stats.json : Path to nfl_team_stats.json}
                            {--chunk=1000 : Number of rows per write chunk}';

    protected $description = '[Retired] Unsupported legacy NFL player data import';

    public function handle(): int
    {
        $this->error('nfl:import-player-data-json is retired. It performs no database or file operations. Use the reviewed native restore workflow in docs/operations/database-access.md for approved database recovery, or the supported espn:sync-nfl-games and espn:sync-nfl-game-details acquisition pipeline when an explicit provider refresh is authorized.');

        return Command::FAILURE;
    }
}
