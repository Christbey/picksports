<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\CBB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;
use App\Support\CbbNcaaTournamentResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;

    protected const SYNC_ORPHANED_SCHEDULED_GAMES = true;

    public function __construct(
        EspnService $espnService,
        ?object $updateLivePrediction = null,
        protected ?CbbNcaaTournamentResolver $tournamentResolver = null,
        protected ?SyncTeams $syncTeams = null,
    ) {
        $this->tournamentResolver ??= app(CbbNcaaTournamentResolver::class);
        $this->syncTeams ??= app(SyncTeams::class, ['espnService' => $espnService]);

        parent::__construct($espnService, $updateLivePrediction);
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    protected function buildGameAttributes(object $dto, array $eventData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $attributes = array_merge(
            parent::buildGameAttributes($dto, $eventData, $homeTeam, $awayTeam),
            $this->tournamentResolver?->resolveFromEspnEvent($eventData) ?? [],
        );

        $existingGame = Game::query()
            ->where('espn_event_id', $dto->espnEventId)
            ->first();

        if ($existingGame && $this->tournamentResolver) {
            return $this->tournamentResolver->mergeOntoExistingGame($existingGame, $attributes);
        }

        return $attributes;
    }

    protected function shouldAutoCreateMissingTeams(): bool
    {
        return true;
    }

    protected function createMissingTeamFromEventData(array $eventData, string $homeAway, string $espnTeamId): ?Model
    {
        $created = $this->syncTeams?->executeForEspnId($espnTeamId);

        return $created ? Team::query()->where('espn_id', $espnTeamId)->first() : null;
    }
}
