<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = '';

    protected const PLAY_MODEL_CLASS = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const PLAY_DTO_CLASS = '';

    protected const GAME_LOOKUP_COLUMN = 'espn_event_id';

    protected const USE_GAME_PLAYS_PAYLOAD = false;

    protected const USE_EVENT_ID_AS_COMPETITION_ID = false;

    protected const SKIP_EMPTY_PLAY_ID = false;

    /** @var array<string, Model|null> */
    private array $possessionTeams = [];

    private bool $teamsPreloaded = false;

    public function __construct(
        protected BaseEspnService $espnService
    ) {}

    public function execute(string $eventId): int
    {
        $gameModel = $this->gameModelClass();
        $game = $gameModel::query()->where($this->gameLookupColumn(), $eventId)->first();

        if (! $game) {
            return 0;
        }

        $plays = $this->fetchPlaysPayload($eventId);

        if (! is_array($plays)) {
            return 0;
        }

        if ($plays === []) {
            return 0;
        }
        $playModel = $this->playModelClass();
        $dtoClass = $this->playDtoClass();
        $dtos = [];
        $hasStoredPlays = null;
        foreach ($plays as $index => $playData) {
            if (! is_array($playData) || ! filled($playData['id'] ?? null)) {
                if (is_array($playData) && $this->shouldSkipPlay($playData)
                    && ! ($hasStoredPlays ??= $playModel::query()->where('game_id', $game->id)->exists())) {
                    continue;
                }

                return 0;
            }
            $dtos[] = $dtoClass::fromEspnResponse($playData, $index);
        }
        if ($dtos === []) {
            return 0;
        }
        $teamModel = $this->teamModelClass();
        $ids = collect($dtos)->flatMap(fn (object $dto): array => [$dto->possessionTeamEspnId ?? null, $dto->battingTeamEspnId ?? null, $dto->pitchingTeamEspnId ?? null])->filter()->unique();
        $this->possessionTeams = $teamModel::query()->whereIn('espn_id', $ids)->get()->keyBy('espn_id')->all();
        $this->teamsPreloaded = true;
        try {
            $rows = [];
            foreach ($dtos as $dto) {
                $attributes = $dto->toArray();
                $attributes['game_id'] = $game->id;
                $this->applyTeamRelations($attributes, $dto);
                $rows[] = $attributes;
            }

            return $game->getConnection()->transaction(function () use ($game, $playModel, $rows) {
                $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();
                $playModel::query()->where('game_id', $game->id)->delete();
                foreach ($rows as $row) {
                    $playModel::query()->create($row);
                }

                return count($rows);
            });
        } finally {
            $this->possessionTeams = [];
            $this->teamsPreloaded = false;
        }
    }

    protected function fetchPlaysPayload(string $eventId): ?array
    {
        if ($this->useGamePlaysPayload()) {
            $gameData = $this->espnService->getGame($eventId);

            return isset($gameData['plays']) && is_array($gameData['plays']) ? $gameData['plays'] : null;
        }

        $competitionId = $this->resolveCompetitionId($eventId);

        if (! $competitionId) {
            return null;
        }

        $response = $this->espnService->getPlays($eventId, $competitionId);

        if (! $response || ! isset($response['items']) || ! is_array($response['items'])) {
            return null;
        }

        if (isset($response['count']) && (int) $response['count'] > count($response['items'])) {
            throw new \RuntimeException('Incomplete play feed; preserving stored plays.');
        }

        return $response['items'];
    }

    protected function resolveCompetitionId(string $eventId): ?string
    {
        if ($this->useEventIdAsCompetitionId()) {
            return $eventId;
        }

        $gameData = $this->espnService->getGame($eventId);

        if (isset($gameData['competitions'][0]['id'])) {
            return (string) $gameData['competitions'][0]['id'];
        }

        if (isset($gameData['header']['competitions'][0]['id'])) {
            return (string) $gameData['header']['competitions'][0]['id'];
        }

        return null;
    }

    protected function shouldSkipPlay(array $playData): bool
    {
        if (static::SKIP_EMPTY_PLAY_ID && empty($playData['id'])) {
            return true;
        }

        return false;
    }

    protected function gameLookupColumn(): string
    {
        return static::GAME_LOOKUP_COLUMN;
    }

    protected function applyTeamRelations(array &$playAttributes, object $dto): void
    {
        if (! isset($dto->possessionTeamEspnId) || ! $dto->possessionTeamEspnId) {
            return;
        }

        $possessionTeam = $this->resolvePlayTeam((string) $dto->possessionTeamEspnId);

        if ($possessionTeam) {
            $playAttributes['possession_team_id'] = $possessionTeam->id;
        }
    }

    protected function resolvePlayTeam(string $espnId): ?Model
    {
        if ($this->teamsPreloaded) {
            return $this->possessionTeams[$espnId] ?? null;
        }
        $teamModel = $this->teamModelClass();

        return $teamModel::query()->where('espn_id', $espnId)->first();
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
    protected function playModelClass(): string
    {
        if (static::PLAY_MODEL_CLASS === '') {
            throw new \RuntimeException('PLAY_MODEL_CLASS must be defined.');
        }

        return static::PLAY_MODEL_CLASS;
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
    protected function playDtoClass(): string
    {
        if (static::PLAY_DTO_CLASS === '') {
            throw new \RuntimeException('PLAY_DTO_CLASS must be defined.');
        }

        return static::PLAY_DTO_CLASS;
    }

    protected function useGamePlaysPayload(): bool
    {
        return static::USE_GAME_PLAYS_PAYLOAD;
    }

    protected function useEventIdAsCompetitionId(): bool
    {
        return static::USE_EVENT_ID_AS_COMPETITION_ID;
    }
}
