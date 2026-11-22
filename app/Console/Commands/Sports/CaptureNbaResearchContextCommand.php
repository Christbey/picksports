<?php

namespace App\Console\Commands\Sports;

use App\Models\NBA\Game;
use App\Services\NBA\Research\CaptureGameContext;
use Illuminate\Console\Command;

class CaptureNbaResearchContextCommand extends Command
{
    protected $signature = 'nba:capture-research-context {game : Selected NBA game ID}';

    protected $description = 'Explicitly capture bounded NBA JSON context sources; no model generation or AI review';

    public function handle(CaptureGameContext $capture): int
    {
        $snapshot = $capture->capture(Game::query()->with('sportEvent')->findOrFail($this->argument('game')));
        $this->line(json_encode(['snapshot_id' => $snapshot->id, 'errors' => collect($snapshot->responses)->map(fn ($r) => $r['error_code'])->all()], JSON_THROW_ON_ERROR));

        return collect($snapshot->responses)->contains(fn ($r) => $r['error_code'] !== null) ? self::FAILURE : self::SUCCESS;
    }
}
