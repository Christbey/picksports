<?php

namespace App\Actions\ESPN;

use App\DataTransferObjects\ESPN\GameData;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = '';

    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = false;

    protected object $updateLivePrediction;

    public function __construct(
        protected BaseEspnService $espnService,
        ?object $updateLivePrediction = null
    ) {
        $this->updateLivePrediction = $updateLivePrediction
            ?? app($this->updateLivePredictionActionClass());
    }

    protected function getUniqueGameKey(): string
    {
        return 'espn_event_id';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    protected function getResponseEvents(array $response): array
    {
        return is_array($response['events'] ?? null) ? $response['events'] : [];
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    protected function gameDtoFromResponse(array $eventData): object
    {
        return GameData::fromEspnResponse($eventData);
    }

    protected function shouldSyncOrphanedInProgressGames(): bool
    {
        return static::SYNC_ORPHANED_IN_PROGRESS_GAMES;
    }

    protected function shouldAutoCreateMissingTeams(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function getInProgressStatuses(): array
    {
        return GameData::inProgressStatuses();
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array{0:?Model,1:?Model}
     */
    protected function resolveTeams(object $dto, array $eventData): array
    {
        $homeTeam = $this->findTeamByEspnId($dto->homeTeamEspnId);
        $awayTeam = $this->findTeamByEspnId($dto->awayTeamEspnId);

        if ($this->shouldAutoCreateMissingTeams()) {
            if (! $homeTeam) {
                $homeTeam = $this->createMissingTeamFromEventData($eventData, 'home', $dto->homeTeamEspnId);
            }
            if (! $awayTeam) {
                $awayTeam = $this->createMissingTeamFromEventData($eventData, 'away', $dto->awayTeamEspnId);
            }
        }

        return [$homeTeam, $awayTeam];
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    protected function createMissingTeamFromEventData(array $eventData, string $homeAway, string $espnTeamId): ?Model
    {
        return null;
    }

    protected function findTeamByEspnId(string $espnId): ?Model
    {
        $teamModel = $this->teamModelClass();

        return $teamModel::query()->where('espn_id', $espnId)->first();
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    protected function buildGameAttributes(object $dto, array $eventData, Model $homeTeam, Model $awayTeam): array
    {
        $attributes = method_exists($dto, 'toArray') ? $dto->toArray() : [
            'espn_event_id' => $dto->espnEventId,
        ];

        return array_merge($attributes, [
            'espn_uid' => $eventData['uid'] ?? null,
            'home_team_id' => $homeTeam->getKey(),
            'away_team_id' => $awayTeam->getKey(),
        ]);
    }

    public function execute(string $date): int
    {
        $response = $this->espnService->getScoreboard($date);
        if (! is_array($response)) {
            return 0;
        }

        $events = $this->getResponseEvents($response);
        if ($events === []) {
            return 0;
        }

        $gameModel = $this->gameModelClass();
        $uniqueKey = $this->getUniqueGameKey();
        $synced = 0;
        $scoreboardEventIds = [];

        foreach ($events as $eventData) {
            if (empty($eventData['id'])) {
                continue;
            }

            $scoreboardEventIds[] = (string) $eventData['id'];
            $dto = $this->gameDtoFromResponse($eventData);
            [$homeTeam, $awayTeam] = $this->resolveTeams($dto, $eventData);

            if (! $homeTeam || ! $awayTeam) {
                continue;
            }

            $attributes = $this->buildGameAttributes($dto, $eventData, $homeTeam, $awayTeam);
            $existingGame = $gameModel::query()->where($uniqueKey, $dto->espnEventId)->first();

            if ($existingGame) {
                if (! in_array($existingGame->status, GameData::finalStatuses(), true)) {
                    $existingGame->update($attributes);
                }
                $game = $existingGame;
            } else {
                $game = $gameModel::query()->create($attributes);
            }

            $this->updateLivePrediction->execute($game);
            $synced++;
        }

        if ($this->shouldSyncOrphanedInProgressGames()) {
            $synced += $this->syncOrphanedInProgressGames($scoreboardEventIds);
        }

        return $synced;
    }

    /**
     * @param  array<int, string>  $scoreboardEventIds
     */
    protected function syncOrphanedInProgressGames(array $scoreboardEventIds): int
    {
        $gameModel = $this->gameModelClass();
        $uniqueKey = $this->getUniqueGameKey();

        $orphanedGames = $gameModel::query()
            ->whereIn('status', $this->getInProgressStatuses())
            ->when($scoreboardEventIds, fn ($q) => $q->whereNotIn($uniqueKey, $scoreboardEventIds))
            ->get();

        $synced = 0;

        foreach ($orphanedGames as $game) {
            $gameData = $this->espnService->getGame((string) $game->{$uniqueKey});
            if (! is_array($gameData)) {
                usleep(300_000);
                continue;
            }

            $this->updateGameFromSummary($gameData, $game);
            $this->updateLivePrediction->execute($game->refresh());
            $synced++;
            usleep(300_000);
        }

        return $synced;
    }

    /**
     * @param  array<string, mixed>  $gameData
     */
    protected function updateGameFromSummary(array $gameData, Model $game): void
    {
        $header = $gameData['header'] ?? [];
        $competition = $header['competitions'][0] ?? [];
        $competitors = $competition['competitors'] ?? [];
        $status = $competition['status'] ?? [];

        $homeTeam = collect($competitors)->firstWhere('homeAway', 'home');
        $awayTeam = collect($competitors)->firstWhere('homeAway', 'away');
        $normalizedStatus = GameData::normalizeStatus((string) ($status['type']['name'] ?? 'scheduled'));

        $game->update([
            'status' => $normalizedStatus,
            'home_score' => isset($homeTeam['score']) ? (int) $homeTeam['score'] : $game->home_score,
            'away_score' => isset($awayTeam['score']) ? (int) $awayTeam['score'] : $game->away_score,
            'home_linescores' => $homeTeam['linescores'] ?? $game->home_linescores,
            'away_linescores' => $awayTeam['linescores'] ?? $game->away_linescores,
            'period' => isset($status['period']) ? (int) $status['period'] : $game->period,
            'game_clock' => $status['displayClock'] ?? $game->game_clock,
        ]);
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        if (static::GAME_MODEL_CLASS === '') {
            throw new \RuntimeException('GAME_MODEL_CLASS must be defined.');
        }

        return static::GAME_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        if (static::TEAM_MODEL_CLASS === '') {
            throw new \RuntimeException('TEAM_MODEL_CLASS must be defined.');
        }

        return static::TEAM_MODEL_CLASS;
    }

    /**
     * @return class-string
     */
    protected function updateLivePredictionActionClass(): string
    {
        if (static::UPDATE_LIVE_PREDICTION_ACTION_CLASS === '') {
            throw new \RuntimeException('UPDATE_LIVE_PREDICTION_ACTION_CLASS must be defined.');
        }

        return static::UPDATE_LIVE_PREDICTION_ACTION_CLASS;
    }
}
