<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;
use App\Actions\GradePlayerProps;
use App\Models\NFL\Game;
use App\Services\ESPN\NFL\EspnService;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService, SyncPlayerStats $syncPlayerStats, SyncTeamStats $syncTeamStats, SyncPlays $syncPlays)
    {
        parent::__construct($espnService, $syncPlayerStats, $syncTeamStats, $syncPlays);
    }

    protected function includeGameUpdatedFlag(): bool
    {
        return true;
    }

    protected function updateGame(array $gameData, Model $game): bool
    {
        return app(SyncGameMetadata::class)->apply($gameData, $game);
    }

    public function execute(string $eventId): array
    {
        $result = parent::execute($eventId);
        $game = Game::where('espn_event_id', $eventId)->first();
        if ($game?->status === 'STATUS_FINAL' && $result['player_stats'] > 0) {
            $grading = app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $game->id);
            if ($grading['graded'] > 0) {
                app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
            }
        }

        return $result;
    }
}
