<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Models\CBB\Game;
use App\Services\CBB\CbbDataQuality;
use RuntimeException;
use Throwable;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function execute(string $eventId): array
    {
        $game = Game::query()->where('espn_event_id', $eventId)->first();
        if ($game === null || ! ctype_digit($eventId)) {
            throw new RuntimeException('CBB details require a real stored ESPN event.');
        }
        $game->update(['details_attempted_at' => now(), 'details_status' => 'running']);
        try {
            $result = parent::execute($eventId);
            $game->refresh();
            if (! ($result['game_updated'] ?? false)) {
                throw new RuntimeException('CBB summary response is unavailable.');
            }
            if ($game->status === 'STATUS_FINAL' && (app(CbbDataQuality::class)->boxScoreIssues($game, $game->teamStats()->get()) !== []
                || $game->playerStats()->count() === 0)) {
                throw new RuntimeException('CBB final details are incomplete.');
            }
            $game->update(['details_completed_at' => now(), 'details_status' => 'complete']);

            return $result;
        } catch (Throwable $exception) {
            $game->update(['details_status' => 'failed']);

            throw $exception;
        }
    }
}
