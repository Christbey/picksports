<?php

namespace App\Actions\ESPN\WNBA;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Services\ESPN\WNBA\EspnService;

class SyncGames
{
    public function __construct(
        protected EspnService $espnService
    ) {}

    public function execute(int $season, int $seasonType, int $week): int
    {
        $response = $this->espnService->getGames($season, $seasonType, $week);

        if (! $response || ! isset($response['items'])) {
            return 0;
        }

        $synced = 0;

        foreach ($response['items'] as $gameData) {
            if (empty($gameData['id'])) {
                continue;
            }

            $dto = GameData::fromEspnResponse($gameData);

            $homeTeam = Team::query()->where('espn_id', $dto->homeTeamEspnId)->first();
            $awayTeam = Team::query()->where('espn_id', $dto->awayTeamEspnId)->first();

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $gameAttributes = $dto->toArray();
            $gameAttributes['home_team_id'] = $homeTeam->id;
            $gameAttributes['away_team_id'] = $awayTeam->id;

            Game::updateOrCreate(
                ['espn_id' => $dto->espnEventId],
                $gameAttributes
            );

            $synced++;
        }

        return $synced;
    }
}
