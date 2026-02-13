<?php

namespace App\Actions\ESPN\CFB;

use App\DataTransferObjects\ESPN\CollegeTeamData;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;

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

            $dto = CollegeTeamData::fromEspnResponse($team);

            Team::updateOrCreate(
                ['espn_id' => $dto->espnId],
                $dto->toArray()
            );

            $synced++;
        }

        return $synced;
    }
}
