<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncGames;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;
use App\Support\CfbPostseasonRoundResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    public function __construct(
        EspnService $espnService,
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
