<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncGames;
use App\DataTransferObjects\ESPN\GameData;
use App\Support\CfbPostseasonRoundResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    public function __construct(
        \App\Services\ESPN\CFB\EspnService $espnService,
        protected CfbPostseasonRoundResolver $postseasonRoundResolver,
    ) {
        parent::__construct($espnService);
    }

    protected function buildGameAttributes(GameData $dto, array $gameData, Model $homeTeam, Model $awayTeam): array
    {
        return array_merge(
            parent::buildGameAttributes($dto, $gameData, $homeTeam, $awayTeam),
            [
                'postseason_round' => $this->postseasonRoundResolver->resolveFromEspnEvent($gameData),
            ],
        );
    }
}
