<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\CFB\Player;
use App\Models\CFB\Team;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'cfb_player_injuries';

    public function execute(string $teamEspnId): int
    {
        // Reset before the team lookup too: a missing team must not inherit the previous team's success.
        $this->injuryPayloadObserved = false;
        $this->injuryPayloadReliable = false;

        return parent::execute($teamEspnId);
    }

    public function lastSyncReliable(): bool
    {
        return $this->injuryPayloadObserved && $this->injuryPayloadReliable;
    }
}
