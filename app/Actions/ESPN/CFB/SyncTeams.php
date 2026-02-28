<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncTeams;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const TEAM_DTO_CLASS = \App\DataTransferObjects\ESPN\CollegeTeamData::class;
}
