<?php

namespace App\Actions\ESPN\CFB;

use App\DataTransferObjects\ESPN\CollegePlayerData;
use App\Models\CFB\Player;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;

class SyncPlayers
{
    public function __construct(
        protected EspnService $espnService
    ) {}

    public function execute(string $teamEspnId): int
    {
        $response = $this->espnService->getRoster($teamEspnId);

        if (! $response || ! isset($response['athletes'])) {
            return 0;
        }

        $team = Team::query()->where('espn_id', $teamEspnId)->first();

        if (! $team) {
            return 0;
        }

        $synced = 0;

        foreach ($response['athletes'] as $positionGroup) {
            if (! isset($positionGroup['items']) || ! is_array($positionGroup['items'])) {
                continue;
            }

            foreach ($positionGroup['items'] as $athleteData) {
                if (empty($athleteData['id'])) {
                    continue;
                }

                $dto = CollegePlayerData::fromEspnResponse($athleteData);

                $playerAttributes = $dto->toArray();
                $playerAttributes['team_id'] = $team->id;

                Player::updateOrCreate(
                    ['espn_id' => $dto->espnId],
                    $playerAttributes
                );

                $synced++;
            }
        }

        return $synced;
    }

    public function syncAllTeams(): int
    {
        $teams = Team::all();
        $totalSynced = 0;

        foreach ($teams as $team) {
            $totalSynced += $this->execute($team->espn_id);
        }

        return $totalSynced;
    }
}
