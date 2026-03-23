<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractCollegeSyncTeams;
use App\DataTransferObjects\ESPN\CollegeTeamData;
use App\Models\WCBB\Team;

class SyncTeams extends AbstractCollegeSyncTeams
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_DTO_CLASS = CollegeTeamData::class;

    protected const SPORT_LABEL = 'WCBB';

    protected const CONFERENCE_API_BASE_URL = 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/womens-college-basketball/groups';
}
