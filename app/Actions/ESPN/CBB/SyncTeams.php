<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractCollegeSyncTeams;

class SyncTeams extends AbstractCollegeSyncTeams
{
    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;

    protected const TEAM_DTO_CLASS = \App\DataTransferObjects\ESPN\CollegeTeamData::class;
    protected const SPORT_LABEL = 'CBB';
    protected const CONFERENCE_API_BASE_URL = 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/mens-college-basketball/groups';
}
