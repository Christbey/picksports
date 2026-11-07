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

    protected function canRetireInjury(object $row): bool
    {
        $source = data_get(json_decode((string) $row->raw_payload, true), 'source');

        return ! is_string($source) || (! str_starts_with($source, 'official_') && $source !== 'source_verified_user_requested_correction');
    }
}
