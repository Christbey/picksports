<?php

namespace App\Console\Commands\CFB;

use App\Actions\ESPN\CFB\SyncGameDetails;
use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbDataReadiness;
use Illuminate\Console\Command;

class RepairGameDataCommand extends Command
{
    protected $signature = 'cfb:repair-game-data {--season=} {--game=} {--only-failed} {--resume} {--max-games=50} {--dry-run}';

    protected $description = 'Repair bounded completed-game batches and preserve previously accepted data';

    public function handle(SyncGameDetails $sync, CfbDataReadiness $readiness): int
    {
        if ((! $this->option('season') && ! $this->option('game')) || (int) $this->option('max-games') < 1) {
            return self::FAILURE;
        }
        $rows = [];
        $remaining = 0;
        foreach (Game::where('status', 'STATUS_FINAL')->whereNotNull('espn_event_id')
            ->when($this->option('season'), fn ($q, $v) => $q->where('season', $v))->when($this->option('game'), fn ($q, $v) => $q->whereKey($v))->lazyById(25) as $game) {
            if (($this->option('only-failed') || $this->option('resume')) && $readiness->forGame($game)['ready']) {
                continue;
            }
            if (count($rows) >= (int) $this->option('max-games')) {
                $remaining++;

                continue;
            }
            try {
                if (! $this->option('dry-run')) {
                    $sync->execute((string) $game->espn_event_id);
                }
                $rows[] = ['game_id' => $game->id, ...$readiness->forGame($game)];
            } catch (\Throwable $e) {
                report($e);
                $rows[] = ['game_id' => $game->id, 'ready' => false, 'error' => $e->getMessage()];
            }

        }
        $this->line(json_encode(['dry_run' => (bool) $this->option('dry-run'), 'remaining_unprocessed' => $remaining, 'games' => $rows], JSON_THROW_ON_ERROR));

        return $this->option('dry-run') || ($remaining === 0 && collect($rows)->every(fn ($r) => $r['ready'])) ? self::SUCCESS : 2;
    }
}
