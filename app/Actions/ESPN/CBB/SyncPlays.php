<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\CbbPlayData;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService)
    {
        parent::__construct($espnService);
    }

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = CbbPlayData::class;

    protected const SKIP_UNCHANGED_REFRESH = true;

    protected function fetchPlaysPayload(string $eventId): ?array
    {
        $plays = parent::fetchPlaysPayload($eventId);
        $game = Game::query()->with(['homeTeam', 'awayTeam'])->where('espn_event_id', $eventId)->first();
        if ($plays && $game) {
            $teamIds = [(string) $game->homeTeam?->espn_id, (string) $game->awayTeam?->espn_id];
            foreach ($plays as $index => $raw) {
                $teamId = CbbPlayData::fromEspnResponse($raw, $index)->possessionTeamEspnId;
                if ($teamId !== null && ! in_array($teamId, $teamIds, true)) {
                    throw new \RuntimeException('CBB play references a team outside the matchup; preserving stored plays.');
                }
            }
        }
        if ($plays && $game?->status === config('cbb.statuses.final')) {
            $last = $plays[array_key_last($plays)];
            if (! isset($last['homeScore'], $last['awayScore'])
                || (int) $last['homeScore'] !== (int) $game->home_score
                || (int) $last['awayScore'] !== (int) $game->away_score) {
                throw new \RuntimeException('CBB play feed does not reach the stored final score; preserving stored plays.');
            }
        }

        return $plays;
    }
}
