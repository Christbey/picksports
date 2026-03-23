<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\BaseballPlayData;
use App\Models\MLB\Game;
use App\Models\MLB\Play;
use App\Models\MLB\Team;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = BaseballPlayData::class;

    protected const USE_GAME_PLAYS_PAYLOAD = true;

    protected const SKIP_EMPTY_PLAY_ID = true;

    protected function applyTeamRelations(array &$playAttributes, object $dto): void
    {
        if (isset($dto->battingTeamEspnId) && $dto->battingTeamEspnId) {
            $battingTeam = Team::query()->where('espn_id', $dto->battingTeamEspnId)->first();
            if ($battingTeam) {
                $playAttributes['batting_team_id'] = $battingTeam->id;
            }
        }

        if (isset($dto->pitchingTeamEspnId) && $dto->pitchingTeamEspnId) {
            $pitchingTeam = Team::query()->where('espn_id', $dto->pitchingTeamEspnId)->first();
            if ($pitchingTeam) {
                $playAttributes['pitching_team_id'] = $pitchingTeam->id;
            }
        }
    }
}
