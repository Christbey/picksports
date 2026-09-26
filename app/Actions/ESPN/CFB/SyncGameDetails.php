<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\CFB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Actions\GradePlayerProps;
use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\SportsViewCache;
use Illuminate\Support\Facades\DB;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function execute(string $eventId): array
    {
        $game = Game::where('espn_event_id', $eventId)->first();
        $empty = ['plays' => 0, 'player_stats' => 0, 'team_stats' => 0, 'game_updated' => false];
        if (! $game || ! ($payload = $this->espnService->getGame($eventId))) {
            return $empty;
        }
        $validation = app(CfbGameDataValidator::class)->boxscore($payload, $game);
        $check = app(CfbSourceRevision::class)->record($game, 'boxscore', $payload, $validation);
        $result = $empty;
        $result['game_updated'] = $this->updateGame($payload, $game);
        app(SportEventIdentitySynchronizer::class)->sync('cfb', $game->fresh());
        if ($validation['state'] === 'complete' && ! $check->isCurrentAccepted()) {
            $counts = DB::transaction(function () use ($payload, $game, $check) {
                Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
                $check->refresh();
                if ($check->isCurrentAccepted()) {
                    return ['player_stats' => 0, 'team_stats' => 0];
                }
                $counts = ['player_stats' => $this->syncPlayerStats->execute($payload, $game), 'team_stats' => $this->syncTeamStats->execute($payload, $game)];
                $check->update(['accepted_at' => now()]);

                return $counts;
            });
            $result = [...$result, ...$counts];
        }
        $result['plays'] = $this->syncPlays->execute($eventId);
        $game = Game::where('espn_event_id', $eventId)->first();
        if ($game?->status === 'STATUS_FINAL' && $result['player_stats'] > 0) {
            $grading = app(GradePlayerProps::class)->executeForGame('americanfootball_ncaaf', $game->id);
            if ($grading['graded'] > 0) {
                app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
            }
        }

        if ($game && ($game->liveSnapshots()->exists() || in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true))) {
            app(UpdateLivePrediction::class)->execute($game);
        }

        return $result;
    }
}
