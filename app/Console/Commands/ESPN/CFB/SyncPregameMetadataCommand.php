<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Models\CFB\Game;
use App\Services\Validation\CfbValidationCoverage;
use Illuminate\Console\Command;

class SyncPregameMetadataCommand extends Command
{
    protected $signature = 'espn:sync-cfb-pregame-metadata {--season=} {--days-forward=8} {--limit=200} {--game-id=}';

    protected $description = 'Verify upcoming CFB kickoff and venue metadata without fetching plays or box scores';

    public function handle(SyncGameMetadata $sync, CfbValidationCoverage $coverage): int
    {
        $days = (int) $this->option('days-forward');
        $limit = (int) $this->option('limit');
        if ($days < 1 || $days > 8 || $limit < 1 || $limit > 200) {
            return self::FAILURE;
        }
        // Include yesterday's placeholders so an incorrectly early kickoff can be recovered.
        $games = Game::query()->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'])
            ->whereDate('game_date', '>=', now()->subDay()->toDateString())
            ->whereDate('game_date', '<=', now()->addDays($days)->toDateString())
            ->when($this->option('season'), fn ($q, $v) => $q->where('season', (int) $v))
            ->when($this->option('game-id'), fn ($q, $v) => $q->whereKey((int) $v))
            ->orderBy('game_date')->orderBy('id')
            ->get(['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id'])
            ->filter(fn ($game) => $coverage->requiredTeamIds($game) !== [])->values();
        $failed = 0;
        foreach ($games->take($limit) as $game) {
            try {
                $sync->execute($game);
            } catch (\Throwable $error) {
                report($error);
                $failed++;
                $this->warn("Game {$game->id}: kickoff metadata could not be verified.");
            }
        }
        $deferred = max(0, $games->count() - $limit);
        $this->info('CFB pregame metadata: verified '.($games->take($limit)->count() - $failed).", failed {$failed}, deferred {$deferred}.");

        return $failed > 0 || $deferred > 0 ? self::FAILURE : self::SUCCESS;
    }
}
