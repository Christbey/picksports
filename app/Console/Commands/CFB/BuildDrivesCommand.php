<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbDriveBuilder;
use Illuminate\Console\Command;

class BuildDrivesCommand extends Command
{
    protected $signature = 'cfb:build-drives {--season=} {--game=} {--only-changed}';

    protected $description = 'Build versioned drives from accepted CFB play revisions';

    public function handle(CfbDriveBuilder $builder): int
    {
        if (! $this->option('season') && ! $this->option('game')) {
            return self::FAILURE;
        }
        $failed = 0;
        foreach (Game::where('status', 'STATUS_FINAL')->when($this->option('season'), fn ($q, $v) => $q->where('season', $v))
            ->when($this->option('game'), fn ($q, $v) => $q->whereKey($v))->lazyById(50) as $game) {
            $result = $builder->build($game, (bool) $this->option('only-changed'));
            $failed += (int) ($result['state'] !== 'complete');
            $this->line(json_encode(['game_id' => $game->id, ...$result]));
        }

        return $failed ? 2 : self::SUCCESS;
    }
}
