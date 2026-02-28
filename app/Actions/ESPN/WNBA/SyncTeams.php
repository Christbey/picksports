<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncTeams;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = \App\Models\WNBA\Team::class;

    protected const TEAM_DTO_CLASS = \App\DataTransferObjects\ESPN\TeamData::class;
}
