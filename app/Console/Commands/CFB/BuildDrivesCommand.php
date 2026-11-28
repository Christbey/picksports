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
        $seasonProvided = $this->input->hasParameterOption('--season', true);
        $gameProvided = $this->input->hasParameterOption('--game', true);
        if (! $seasonProvided && ! $gameProvided) {
            return self::FAILURE;
        }
        $season = $seasonProvided ? filter_var($this->option('season'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        $gameId = $gameProvided ? filter_var($this->option('game'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if (($seasonProvided && $season === false) || ($gameProvided && $gameId === false)) {
            $this->error('Supplied season and game options require positive integers.');

            return self::FAILURE;
        }
        $failed = 0;
        foreach (Game::where('status', 'STATUS_FINAL')->when($seasonProvided, fn ($q) => $q->where('season', $season))
            ->when($gameProvided, fn ($q) => $q->whereKey($gameId))->lazyById(50) as $game) {
            $result = $builder->build($game, (bool) $this->option('only-changed'));
            $failed += (int) ($result['state'] !== 'complete');
            $this->line(json_encode(['game_id' => $game->id, ...$result]));
        }

        return $failed ? 2 : self::SUCCESS;
    }
}
