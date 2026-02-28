<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncTeams;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const TEAM_DTO_CLASS = \App\DataTransferObjects\ESPN\TeamData::class;
}
