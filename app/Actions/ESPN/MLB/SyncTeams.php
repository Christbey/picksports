<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncTeams;
use App\DataTransferObjects\ESPN\TeamData;
use App\Models\MLB\Team;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_DTO_CLASS = TeamData::class;

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
            'logo_url' => $this->mirrorLogo(
                $dto->logoUrl,
                $this->sportKey(),
                $this->teamAssetIdentifier([
                    'location' => $dto->location,
                    'name' => $dto->name,
                ], (string) $dto->espnId)
            ),
        ];
    }
}
