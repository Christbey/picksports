<?php

namespace App\Actions\ESPN\NBA;

use App\DataTransferObjects\ESPN\TeamData;
use App\Models\NBA\Team;
use App\Services\ESPN\NBA\EspnService;

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

            $teamAttributes = $dto->toArray();
            // Map pro-style fields to NBA database schema
            $teamAttributes['school'] = $teamAttributes['location'] ?? null;
            $teamAttributes['mascot'] = $teamAttributes['name'] ?? null;
            unset($teamAttributes['location'], $teamAttributes['name']);

            Team::updateOrCreate(
                ['espn_id' => $dto->espnId],
                $teamAttributes
            );

            $synced++;
        }

        return $synced;
    }
}
