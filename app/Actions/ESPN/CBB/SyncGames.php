<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncGames;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;
use App\Support\CbbNcaaTournamentResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    public function __construct(
        EspnService $espnService,
        protected CbbNcaaTournamentResolver $tournamentResolver,
    ) {
        parent::__construct($espnService);
    }

    protected function buildGameAttributes(GameData $dto, array $gameData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $attributes = array_merge(
            parent::buildGameAttributes($dto, $gameData, $homeTeam, $awayTeam),
            $this->tournamentResolver->resolveFromEspnEvent($gameData),
        );

        $existingGame = Game::query()
            ->where('espn_event_id', $dto->espnEventId)
            ->first();

        if ($existingGame) {
            return $this->tournamentResolver->mergeOntoExistingGame($existingGame, $attributes);
        }

        return $attributes;
    }
}
