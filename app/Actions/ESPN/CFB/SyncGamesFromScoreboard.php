<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\CFB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Support\CfbPostseasonRoundResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;

    public function __construct(
        \App\Services\ESPN\CFB\EspnService $espnService,
        ?object $updateLivePrediction = null,
        protected ?CfbPostseasonRoundResolver $postseasonRoundResolver = null,
    ) {
        $this->postseasonRoundResolver ??= app(CfbPostseasonRoundResolver::class);

        parent::__construct($espnService, $updateLivePrediction);
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    protected function buildGameAttributes(object $dto, array $eventData, Model $homeTeam, Model $awayTeam): array
    {
        return array_merge(
            parent::buildGameAttributes($dto, $eventData, $homeTeam, $awayTeam),
            [
                'postseason_round' => $this->postseasonRoundResolver?->resolveFromEspnEvent($eventData),
            ],
        );
    }
}
