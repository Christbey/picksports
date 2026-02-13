<?php

namespace App\Actions\ESPN\WNBA;

use App\DataTransferObjects\ESPN\TeamData;
use App\Models\WNBA\Team;
use App\Services\ESPN\WNBA\EspnService;

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

            Team::updateOrCreate(
                ['espn_id' => $dto->espnId],
                $dto->toArray()
            );

            $synced++;
        }

        return $synced;
    }
}
