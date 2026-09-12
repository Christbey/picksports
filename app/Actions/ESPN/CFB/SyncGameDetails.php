<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\CFB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Actions\GradePlayerProps;
use App\Models\CFB\Game;
use App\Support\SportsViewCache;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function execute(string $eventId): array
    {
        $result = parent::execute($eventId);
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
