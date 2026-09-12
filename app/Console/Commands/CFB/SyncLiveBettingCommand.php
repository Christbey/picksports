<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Live\LiveBettingSync;
use Illuminate\Console\Command;

class SyncLiveBettingCommand extends Command
{
    protected $signature = 'cfb:sync-live-betting {--game= : Internal game ID} {--limit=40 : Maximum games per run}';

    protected $description = 'Capture college football live projections, fresh market comparisons and live prop estimates without replacing pregame predictions';

    public function handle(LiveBettingSync $sync): int
    {
        if (($this->option('game') !== null && (! ctype_digit((string) $this->option('game')) || (int) $this->option('game') < 1))
            || ! ctype_digit((string) $this->option('limit')) || (int) $this->option('limit') < 1 || (int) $this->option('limit') > 100) {
            $this->error('Provide a positive game ID and a limit between 1 and 100.');

            return self::FAILURE;
        }
        $games = Game::where(function ($q) {
            $q->whereIn('status', ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'])
                ->orWhere(fn ($q) => $q->where('status', 'STATUS_FINAL')->whereHas('liveSnapshots')
                    ->whereDoesntHave('liveSnapshots', fn ($s) => $s->where('source', 'live_feed')->where('status', 'final')));
        })->when($this->option('game'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('updated_at')->limit((int) $this->option('limit'))->get();
        $failed = 0;
        foreach ($games as $game) {
            try {
                $result = $sync->execute($game);
                $failed += $result['status'] !== 'captured' ? 1 : 0;
                $this->line(json_encode(['game_id' => $game->id, ...$result], JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Live capture failed for game {$game->id} (".class_basename($e).').');
            }
        }
        $this->info(json_encode(['games' => $games->count(), 'failed' => $failed], JSON_THROW_ON_ERROR));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
