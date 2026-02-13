<?php

namespace App\Actions\ESPN\MLB;

use App\DataTransferObjects\ESPN\TeamData;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;

class SyncTeams
{
    public function __construct(
        protected EspnService $espnService
    ) {}

    public function execute(): int
    {
        $response = $this->espnService->getTeams();

        if (! $response || ! isset($response['sports'][0]['leagues'][0]['teams'])) {
            return 0;
        }

        $teams = $response['sports'][0]['leagues'][0]['teams'];
        $synced = 0;

        foreach ($teams as $teamData) {
            $team = $teamData['team'] ?? [];

            if (empty($team['id'])) {
                continue;
            }

            $dto = TeamData::fromEspnResponse($team);

            // MLB uses location, name, and nickname structure
            Team::updateOrCreate(
                ['espn_id' => $dto->espnId],
                [
                    'espn_id' => $dto->espnId,
                    'abbreviation' => $dto->abbreviation,
                    'location' => $dto->location,
                    'name' => $dto->name,
                    'nickname' => $dto->name, // MLB nickname is same as name typically
                    'league' => $dto->conference, // ESPN's conference maps to MLB's league (AL/NL)
                    'division' => $dto->division,
                    'color' => $dto->color,
                    'logo_url' => $dto->logoUrl,
                ]
            );

            $synced++;
        }

        return $synced;
    }
}
