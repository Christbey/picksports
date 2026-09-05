<?php

namespace App\Actions\ESPN;

use App\DataTransferObjects\ESPN\GameData;
use App\Services\ESPN\BaseEspnService;
use App\Services\GameFinalizationDispatcher;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\EspnGameStatusResolver;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const UNIQUE_GAME_KEY = 'espn_event_id';

    /**
     * @var array<string, array<string, mixed>|null>
     */
    protected array $refCache = [];

    public function __construct(
        protected BaseEspnService $espnService,
        protected ?EspnGameStatusResolver $statusResolver = null,
    ) {
        $this->statusResolver ??= app(EspnGameStatusResolver::class);
    }

    protected function getUniqueGameKey(): string
    {
        return static::UNIQUE_GAME_KEY;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    protected function getResponseItems(array $response): array
    {
        $items = is_array($response['items'] ?? null) ? $response['items'] : [];

        return array_values(array_filter(array_map(function (array $item): ?array {
            if (! empty($item['id'])) {
                return $this->normalizeGamePayload($item);
            }

            $ref = trim((string) ($item['$ref'] ?? ''));
            if ($ref === '') {
                return null;
            }

            $payload = $this->resolveRefPayload($ref);

            return is_array($payload) ? $this->normalizeGamePayload($payload) : null;
        }, $items), static fn ($item) => is_array($item) && ! empty($item['id'])));
    }

    /**
     * @param  array<string, mixed>  $gameData
     */
    protected function gameDtoFromResponse(array $gameData): GameData
    {
        return GameData::fromEspnResponse($gameData);
    }

    /**
     * @param  array<string, mixed>  $gameData
     * @return array<string, mixed>
     */
    protected function buildGameAttributes(GameData $dto, array $gameData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $attributes = $dto->toArray();
        [$homeCompetitor, $awayCompetitor] = $this->resolveCompetitors($gameData);
        $attributes['home_team_id'] = $homeTeam?->getKey();
        $attributes['away_team_id'] = $awayTeam?->getKey();
        $attributes['home_team_display_name'] = $this->competitorDisplayName($homeCompetitor);
        $attributes['away_team_display_name'] = $this->competitorDisplayName($awayCompetitor);
        $attributes['home_team_abbreviation'] = $this->competitorAbbreviation($homeCompetitor);
        $attributes['away_team_abbreviation'] = $this->competitorAbbreviation($awayCompetitor);

        return $attributes;
    }

    protected function findTeamByEspnId(string $espnId): ?Model
    {
        $teamModel = $this->teamModelClass();

        return $teamModel::query()->where('espn_id', $espnId)->first();
    }

    public function execute(int $season, int $seasonType, int $week): int
    {
        $response = $this->espnService->getGames($season, $seasonType, $week);

        if (! is_array($response)) {
            return 0;
        }

        $items = $this->getResponseItems($response);
        if ($items === []) {
            return 0;
        }

        $gameModel = $this->gameModelClass();
        $synced = 0;

        foreach ($items as $gameData) {
            if (empty($gameData['id'])) {
                continue;
            }

            $dto = $this->gameDtoFromResponse($gameData);
            $homeTeam = $this->findTeamByEspnId($dto->homeTeamEspnId);
            $awayTeam = $this->findTeamByEspnId($dto->awayTeamEspnId);

            $attributes = $this->buildGameAttributes($dto, $gameData, $homeTeam, $awayTeam);

            if (! $homeTeam || ! $awayTeam) {
                if (! $this->shouldStorePartialGame($attributes)) {
                    continue;
                }
            }

            if (($attributes['home_team_display_name'] ?? null) === 'TBD') {
                $attributes['home_team_display_name'] = null;
                $attributes['home_team_abbreviation'] = null;
            }

            if (($attributes['away_team_display_name'] ?? null) === 'TBD') {
                $attributes['away_team_display_name'] = null;
                $attributes['away_team_abbreviation'] = null;
            }

            if (
                ! $homeTeam
                && ! $awayTeam
                && empty($attributes['home_team_display_name'])
                && empty($attributes['away_team_display_name'])
            ) {
                continue;
            }

            $uniqueKey = $this->getUniqueGameKey();
            $existingGame = $gameModel::query()->where($uniqueKey, $dto->espnEventId)->first();
            if ($existingGame) {
                $attributes = $this->preserveExistingTeamSlots($attributes, $existingGame);
                $attributes['status'] = $this->statusResolver->resolveForUpdate(
                    (string) ($existingGame->status ?? ''),
                    (string) ($attributes['status'] ?? ''),
                    'games',
                    $this->sportKey(),
                );
            } else {
                $attributes['status'] = $this->statusResolver->resolveForCreate(
                    (string) ($attributes['status'] ?? ''),
                    'games',
                    $this->sportKey(),
                );
            }
            $previousStatus = $existingGame ? (string) ($existingGame->status ?? '') : null;

            if ($existingGame) {
                $existingGame->update($attributes);
                $game = $existingGame->fresh();
            } else {
                $game = $gameModel::query()->create($attributes);
            }

            app(SportEventIdentitySynchronizer::class)->sync($this->sportKey(), $game);
            app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, $previousStatus);

            $synced++;
        }

        return $synced;
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
     * @param  array<string, mixed>  $gameData
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function resolveCompetitors(array $gameData): array
    {
        $competition = $gameData['competitions'][0] ?? data_get($gameData, 'header.competitions.0', []);
        $competitors = $competition['competitors'] ?? [];

        return [
            collect($competitors)->firstWhere('homeAway', 'home') ?? [],
            collect($competitors)->firstWhere('homeAway', 'away') ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    protected function competitorDisplayName(array $competitor): ?string
    {
        return $this->firstNonEmptyString([
            data_get($competitor, 'team.displayName'),
            data_get($competitor, 'team.location'),
            data_get($competitor, 'displayName'),
            data_get($competitor, 'name'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    protected function competitorAbbreviation(array $competitor): ?string
    {
        return $this->firstNonEmptyString([
            data_get($competitor, 'team.abbreviation'),
            data_get($competitor, 'abbreviation'),
            data_get($competitor, 'shortDisplayName'),
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function shouldStorePartialGame(array $attributes): bool
    {
        return (bool) ($attributes['is_ncaa_tournament'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function preserveExistingTeamSlots(array $attributes, Model $existingGame): array
    {
        foreach ([
            'home_team_id',
            'away_team_id',
            'home_team_display_name',
            'away_team_display_name',
            'home_team_abbreviation',
            'away_team_abbreviation',
        ] as $key) {
            if (($attributes[$key] ?? null) === null && $existingGame->{$key} !== null) {
                $attributes[$key] = $existingGame->{$key};
            }
        }

        return $attributes;
    }

    protected function sportKey(): string
    {
        $parts = explode('\\', $this->gameModelClass());

        return strtolower($parts[2] ?? '');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveRefPayload(string $ref): ?array
    {
        $url = trim($ref);
        if ($url === '') {
            return null;
        }

        if (array_key_exists($url, $this->refCache)) {
            return $this->refCache[$url];
        }

        try {
            $payload = $this->espnService->getByRef($url);
        } catch (\Throwable) {
            return $this->refCache[$url] = null;
        }

        if (! is_array($payload)) {
            return $this->refCache[$url] = null;
        }

        $eventId = (string) ($payload['id'] ?? '');
        if ($eventId !== '') {
            $summary = $this->espnService->getGame($eventId);
            if (is_array($summary)) {
                return $this->refCache[$url] = $summary;
            }
        }

        return $this->refCache[$url] = $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeGamePayload(array $payload): array
    {
        if (! isset($payload['header']) || ! is_array($payload['header'])) {
            return $payload;
        }

        $header = $payload['header'];
        $competition = $header['competitions'][0] ?? [];

        return array_filter([
            'id' => $header['id'] ?? $payload['id'] ?? null,
            'uid' => $header['uid'] ?? $payload['uid'] ?? null,
            'date' => $competition['date'] ?? $header['date'] ?? $payload['date'] ?? null,
            'name' => $competition['name'] ?? $header['name'] ?? $payload['name'] ?? null,
            'shortName' => $competition['shortName'] ?? $header['shortName'] ?? $payload['shortName'] ?? null,
            'season' => $header['season'] ?? $payload['season'] ?? null,
            'week' => is_int($header['week'] ?? null) ? ['number' => $header['week']] : ($header['week'] ?? $payload['week'] ?? null),
            'status' => $competition['status'] ?? $payload['status'] ?? null,
            'competitions' => $header['competitions'] ?? $payload['competitions'] ?? null,
        ], static fn ($value) => $value !== null);
    }
}
