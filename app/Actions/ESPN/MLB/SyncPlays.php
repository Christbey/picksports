<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\BaseballPlayData;
use App\Models\MLB\Game;
use App\Models\MLB\Play;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService)
    {
        parent::__construct($espnService);
    }

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = BaseballPlayData::class;

    protected const USE_GAME_PLAYS_PAYLOAD = true;

    protected const SKIP_EMPTY_PLAY_ID = true;

    protected function applyTeamRelations(array &$playAttributes, object $dto): void
    {
        if (isset($dto->battingTeamEspnId) && $dto->battingTeamEspnId) {
            $battingTeam = $this->resolvePlayTeam((string) $dto->battingTeamEspnId);
            if ($battingTeam) {
                $playAttributes['batting_team_id'] = $battingTeam->id;
            }
        }

        if (isset($dto->pitchingTeamEspnId) && $dto->pitchingTeamEspnId) {
            $pitchingTeam = $this->resolvePlayTeam((string) $dto->pitchingTeamEspnId);
            if ($pitchingTeam) {
                $playAttributes['pitching_team_id'] = $pitchingTeam->id;
            }
        }
    }
}
