<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Game;
use App\Services\MLB\MlbStaleGameReconciler;
use Illuminate\Console\Command;

class ReconcileStaleGamesCommand extends Command
{
    protected $signature = 'mlb:reconcile-stale-games {--season=} {--game=} {--limit=25} {--apply} {--hold-unresolved}';

    protected $description = 'Reconcile stale MLB status from provider identity; optionally retain unverifiable games as unresolved holds.';

    public function handle(MlbStaleGameReconciler $reconciler): int
    {
        $games = Game::whereIn('status', MlbStaleGameReconciler::ACTIVE_STATUSES)
            ->whereDate('game_date', '<', today()->subDays(2))
            ->when(filled($this->option('season')), fn ($q) => $q->where('season', (int) $this->option('season')))
            ->when(filled($this->option('game')), fn ($q) => $q->whereKey((int) $this->option('game')))
            ->with(['homeTeam', 'awayTeam'])->orderBy('id')->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $counts = ['reconciled' => 0, 'held' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($games as $game) {
            try {
                $result = $reconciler->reconcile($game, (bool) $this->option('apply'), (bool) $this->option('hold-unresolved'));
                $counts[$result['outcome']]++;
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } catch (\Throwable $exception) {
                $counts['failed']++;
                $this->error("Game {$game->id}: {$exception->getMessage()}");
            }
        }
        $this->line(json_encode(['counts' => $counts, 'applied' => (bool) $this->option('apply')], JSON_THROW_ON_ERROR));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
