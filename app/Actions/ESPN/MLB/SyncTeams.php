<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncTeams;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = \App\Models\MLB\Team::class;

    protected const TEAM_DTO_CLASS = \App\DataTransferObjects\ESPN\TeamData::class;

    protected function mapTeamAttributes(object $dto, array $resolvedTeam, array $rawTeam): array
    {
        return [
            'espn_id' => $dto->espnId,
            'abbreviation' => $dto->abbreviation,
            'location' => $dto->location,
            'name' => $dto->name,
            'nickname' => $dto->name,
            'league' => $dto->conference,
            'division' => $dto->division,
            'color' => $dto->color,
            'logo_url' => $dto->logoUrl,
        ];
    }
}
