<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncTeams;
use App\DataTransferObjects\ESPN\TeamData;
use App\Models\NFL\Team;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_DTO_CLASS = TeamData::class;
}
