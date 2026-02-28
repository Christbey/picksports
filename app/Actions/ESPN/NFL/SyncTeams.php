<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncTeams;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;

    protected const TEAM_DTO_CLASS = \App\DataTransferObjects\ESPN\TeamData::class;
}
