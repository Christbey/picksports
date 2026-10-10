<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbCfbdTeamBoxscoreSync;
use Illuminate\Console\Command;

class SyncCfbdTeamBoxscoreCommand extends Command
{
    protected $signature = 'cfb:sync-cfbd-team-boxscore {--game=}';

    protected $description = 'Import independently verified CFBD team boxes while retaining player/play quarantine';

    public function handle(CfbCfbdTeamBoxscoreSync $sync): int
    {
        $this->line(json_encode($sync->execute(Game::findOrFail($this->option('game'))), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
