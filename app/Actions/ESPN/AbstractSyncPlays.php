<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use App\Support\ESPN\PlayIdentity;
use App\Support\ESPN\PlayTeamReferences;
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

    protected const SKIP_UNCHANGED_REFRESH = false;

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
        $identity = $this->capturePlayContext($game);
        $plays = $this->fetchPlaysPayload($eventId);
        if (! is_array($plays) || $plays === []) {
            return 0;
        }
        $playModel = $this->playModelClass();
        $dtoClass = $this->playDtoClass();
        $dtos = [];
        $hasStoredPlays = null;
        $seenIds = [];
        foreach ($plays as $index => $playData) {
            $id = is_array($playData) ? PlayIdentity::from($playData['id'] ?? null) : null;
            if (! is_array($playData) || ! filled($id)) {
                if (is_array($playData) && $this->shouldSkipPlay($playData)
                    && ! ($hasStoredPlays ??= $playModel::on($game->getConnection()->getName())->where('game_id', $game->id)->exists())) {
                    continue;
                }

                return 0;
            }
            if (isset($seenIds['id:'.$id])) {
                throw new \RuntimeException('Duplicate play identity; preserving stored plays.');
            }
            $seenIds['id:'.$id] = true;
            $dtos[] = $dtoClass::fromEspnResponse($playData, $index);
        }
        if ($dtos === []) {
            return 0;
        }

        return $game->getConnection()->transaction(function () use ($game, $identity, $playModel, $dtos, $plays) {
            $locked = $this->lockPlayGame($game, $identity);
            try {
                $this->preparePlayTeams($locked, $dtos, $plays, $identity);
                $this->validatePlayBatchForGame($locked, $dtos);
                $rows = [];
                foreach ($dtos as $dto) {
                    $attributes = $dto->toArray();
                    $attributes['game_id'] = $locked->id;
                    if (static::SKIP_UNCHANGED_REFRESH) {
                        $attributes['possession_team_id'] = null;
                    }
                    $this->applyTeamRelations($attributes, $dto);
                    $rows[] = $attributes;
                }
                $query = $playModel::on($locked->getConnection()->getName())->where('game_id', $locked->id);
                if (static::SKIP_UNCHANGED_REFRESH) {
                    $stored = (clone $query)->orderBy('id')->lockForUpdate()->get();
                    if ($stored->count() === count($rows)
                        && ! $stored->contains(fn (Model $play, int $index) => $play->fill($rows[$index])->isDirty())) {
                        return count($rows);
                    }
                }
                $query->delete();
                foreach ($rows as $row) {
                    $playModel::on($locked->getConnection()->getName())->create($row);
                }

                return count($rows);
            } finally {
                $this->clearPlayTeams();
            }
        }, 3);
    }

    /** Capture before provider I/O; a later lock must still identify this event and pair. */
    protected function playGameIdentity(Model $game): array
    {
        return [
            'event' => (string) $game->getRawOriginal($this->gameLookupColumn()),
            'home' => (string) $game->getRawOriginal('home_team_id'),
            'away' => (string) $game->getRawOriginal('away_team_id'),
        ];
    }

    protected function capturePlayContext(Model $game): array
    {
        $teamModel = $this->teamModelClass();
        $teams = $teamModel::on($game->getConnection()->getName())->whereKey([$game->home_team_id, $game->away_team_id])
            ->orderBy('id')->get(['id', 'espn_id']);

        $game->setRelation('homeTeam', $teams->firstWhere('id', $game->home_team_id));
        $game->setRelation('awayTeam', $teams->firstWhere('id', $game->away_team_id));

        return ['game' => $this->playGameIdentity($game), 'teams' => $teams->mapWithKeys(fn (Model $team) => [(string) $team->id => (string) $team->espn_id])->all()];
    }

    protected function lockPlayGame(Model $game, array $identity): Model
    {
        $locked = $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();
        if ($this->playGameIdentity($locked) !== $identity['game']) {
            throw new \RuntimeException('Play game identity changed during provider fetch; preserving stored plays.');
        }

        return $locked;
    }

    /** Resolve only owned teams, after the game lock, on its actual connection. */
    protected function preparePlayTeams(Model $game, array $dtos, array $rawPlays, array $identity): void
    {
        $ids = [$game->home_team_id, $game->away_team_id];
        if (in_array(null, $ids, true) || count(array_unique($ids)) !== 2) {
            throw new \RuntimeException('Play matchup teams are unavailable; preserving stored plays.');
        }
        $teamModel = $this->teamModelClass();
        $teams = $teamModel::on($game->getConnection()->getName())->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($teams->count() !== 2 || $teams->contains(fn (Model $team) => ! filled($team->espn_id))
            || $teams->pluck('espn_id')->map(fn ($id) => (string) $id)->unique()->count() !== 2) {
            throw new \RuntimeException('Play matchup team references are ambiguous; preserving stored plays.');
        }
        if ($teams->mapWithKeys(fn (Model $team) => [(string) $team->id => (string) $team->espn_id])->all() !== $identity['teams']) {
            throw new \RuntimeException('Play provider team bindings changed during fetch; preserving stored plays.');
        }
        $owned = $teams->keyBy('espn_id')->all();
        foreach ($rawPlays as $raw) {
            foreach (PlayTeamReferences::fromPlay($raw) as $id) {
                if (! array_key_exists($id, $owned)) {
                    throw new \RuntimeException('Play references a team outside the matchup; preserving stored plays.');
                }
            }
        }
        foreach ($dtos as $dto) {
            foreach ([$dto->possessionTeamEspnId ?? null, $dto->battingTeamEspnId ?? null, $dto->pitchingTeamEspnId ?? null] as $id) {
                if ($id !== null && ! array_key_exists((string) $id, $owned)) {
                    throw new \RuntimeException('Play references an unresolved matchup team; preserving stored plays.');
                }
            }
        }
        $game->setRelation('homeTeam', $teams->firstWhere('id', $game->home_team_id));
        $game->setRelation('awayTeam', $teams->firstWhere('id', $game->away_team_id));
        $this->possessionTeams = $owned;
        $this->teamsPreloaded = true;
    }

    protected function clearPlayTeams(): void
    {
        $this->possessionTeams = [];
        $this->teamsPreloaded = false;
    }

    protected function validatePlayBatchForGame(Model $game, array $dtos): void {}

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
        throw new \LogicException('Play teams must be resolved under the current game lock.');
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
