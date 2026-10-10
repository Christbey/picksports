<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class NormalizeGameCommand extends Command
{
    protected $signature = 'cfb:normalize-game {--game=}';

    protected $description = 'Rebuild one accepted CFB game in a bounded worker process';

    public function handle(CfbDriveBuilder $builder): int
    {
        $game = Game::findOrFail($this->option('game'));
        if (! app(CfbDataReadiness::class)->forGame($game)['ready']) {
            $this->line(json_encode(['state' => 'blocked', 'reason' => 'unaccepted_historical_sources'], JSON_THROW_ON_ERROR));

            return 2;
        }
        $code = Artisan::call('cfb:calculate-play-epa', ['--game_id' => $game->id, '--rebuild' => true]);
        $drives = $builder->build($game, true);
        $result = ['state' => $code === self::SUCCESS && $drives['state'] === 'complete' ? 'complete' : 'failed', 'drives' => $drives];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['state'] === 'complete' ? self::SUCCESS : 2;
    }
}
