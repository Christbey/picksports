<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncGames;
use App\DataTransferObjects\ESPN\GameData;
use App\Support\CbbNcaaTournamentResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;

    public function __construct(
        \App\Services\ESPN\CBB\EspnService $espnService,
        protected CbbNcaaTournamentResolver $tournamentResolver,
    ) {
        parent::__construct($espnService);
    }

    protected function buildGameAttributes(GameData $dto, array $gameData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        return array_merge(
            parent::buildGameAttributes($dto, $gameData, $homeTeam, $awayTeam),
            $this->tournamentResolver->resolveFromEspnEvent($gameData),
        );
    }
}
