<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflPropShadowService;
use Illuminate\Console\Command;

class SnapshotPropShadowCommand extends Command
{
    protected $signature = 'nfl:snapshot-prop-shadow {--season= : Required NFL season} {--game= : Restrict to one upcoming game}';

    protected $description = 'Freeze the first eligible pregame prop shadow forecast per player/game/market';

    public function handle(NflPropShadowService $shadows): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $game = $this->option('game');
        if (! $season || $season < 2000 || ($game !== null && (! ctype_digit((string) $game) || (int) $game < 1))) {
            $this->error('Supply a valid --season and optional positive --game.');

            return self::FAILURE;
        }
        $this->line(json_encode($shadows->run($season, $game === null ? null : (int) $game), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
