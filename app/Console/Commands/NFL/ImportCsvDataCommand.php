<?php

namespace App\Console\Commands\NFL;

use Illuminate\Console\Command;

class ImportCsvDataCommand extends Command
{
    protected $signature = 'nfl:import-csv-data
                            {--teams=/Users/bey/nfl_teams.csv : Path to nfl_teams.csv}
                            {--games=/Users/bey/nfl_games.csv : Path to nfl_games.csv}
                            {--players=/Users/bey/nfl_players.csv : Path to nfl_players.csv}
                            {--plays=/Users/bey/nfl_plays.csv : Path to nfl_plays.csv}
                            {--player-stats=/Users/bey/nfl_player_stats.csv : Path to nfl_player_stats.csv}
                            {--team-stats=/Users/bey/nfl_team_stats.csv : Path to nfl_team_stats.csv}
                            {--predictions=/Users/bey/nfl_predictions.csv : Path to nfl_predictions.csv}
                            {--append : Keep existing rows and upsert by id instead of truncating first}
                            {--chunk=500 : Number of rows per upsert chunk}';

    protected $description = '[Retired] Unsupported legacy NFL data import';

    public function handle(): int
    {
        $this->error('nfl:import-csv-data is retired. It performs no database or file operations. Use the reviewed native restore workflow in docs/operations/database-access.md for approved database recovery, or the supported espn:sync-nfl-games and espn:sync-nfl-game-details acquisition pipeline when an explicit provider refresh is authorized.');

        return Command::FAILURE;
    }
}
