<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Models\CFB\Game;
use App\Services\Sports\CfbGameCalendarRead;
use App\Services\Validation\CfbValidationCoverage;
use Carbon\CarbonImmutable;
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
        $season = $this->option('season');
        $id = $this->option('game-id');
        foreach ([$season, $id] as $value) {
            if ($value !== null && ((! is_string($value) && ! is_int($value)) || preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $value) !== 1)) {
                return self::FAILURE;
            }
        }
        if ($season === null && $id === null) {
            $season = (string) config('cfb.season.default');
        }
        $query = Game::query()->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'])
            ->when($season !== null, fn ($q) => $q->where('season', $season))
            ->when($id !== null, fn ($q) => $q->whereKey($id));
        $query = app(CfbGameCalendarRead::class)->metadataCandidates(
            $query, CarbonImmutable::now(), CarbonImmutable::now()->addDays($days));
        // All unknown candidates are bounded before hydration/IO; limit overflow is reported as deferred.
        $games = $query->orderBy('game_date')->orderBy('id')
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
