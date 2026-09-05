<?php

namespace App\Actions\ESPN;

use App\DataTransferObjects\ESPN\GameData;
use App\Services\ESPN\BaseEspnService;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\EspnGameStatusResolver;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncGamesFromSchedule
{
    protected const GAME_MODEL_CLASS = Model::class;

    public function __construct(
        protected BaseEspnService $espnService,
        protected ?EspnGameStatusResolver $statusResolver = null,
    ) {
        $this->statusResolver ??= app(EspnGameStatusResolver::class);
    }

    public function execute(string $teamEspnId, ?int $season = null): int
    {
        $response = $this->espnService->getSchedule($teamEspnId, $season);

        if (! $response || ! isset($response['events']) || ! is_array($response['events'])) {
            return 0;
        }

        $synced = 0;
        $gameModel = $this->gameModelClass();

        foreach ($response['events'] as $game) {
            if (empty($game['id'])) {
                continue;
            }

            $dto = GameData::fromEspnResponse($game);

            [$homeTeam, $awayTeam] = $this->resolveTeams($dto, $game);

            $attributes = $homeTeam && $awayTeam
                ? $this->gameAttributes($dto, $game, $homeTeam, $awayTeam)
                : $this->partialGameAttributes($dto, $game, $homeTeam, $awayTeam);

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

            $lookup = $this->gameLookupAttributes($dto);
            $existingGame = $gameModel::query()->where($lookup)->first();
            if ($existingGame) {
                $attributes = $this->preserveExistingTeamSlots($attributes, $existingGame);
                $attributes['status'] = $this->statusResolver->resolveForUpdate(
                    (string) ($existingGame->status ?? ''),
                    (string) ($attributes['status'] ?? ''),
                    'schedule',
                    $this->sportKey(),
                );
            } else {
                $attributes['status'] = $this->statusResolver->resolveForCreate(
                    (string) ($attributes['status'] ?? ''),
                    'schedule',
                    $this->sportKey(),
                );
            }

            if ($existingGame) {
                if ($this->shouldUpdateExistingGame($existingGame, $dto, $game)) {
                    $updateAttributes = $homeTeam && $awayTeam
                        ? $this->existingGameAttributes($dto, $game, $homeTeam, $awayTeam, $existingGame)
                        : $attributes;

                    if (array_key_exists('status', $attributes)) {
                        $updateAttributes['status'] = $attributes['status'];
                    }

                    $existingGame->update($updateAttributes);
                }

                $persistedGame = $existingGame->fresh();
            } else {
                $persistedGame = $gameModel::query()->create($attributes);
            }

            app(SportEventIdentitySynchronizer::class)->sync($this->sportKey(), $persistedGame);
            $synced++;
        }

        return $synced;
    }

    /**
     * @return array{0:?Model,1:?Model}
     */
    abstract protected function resolveTeams(GameData $dto, array $rawGame): array;

    /**
     * @return array<string,mixed>
     */
    abstract protected function gameAttributes(GameData $dto, array $rawGame, Model $homeTeam, Model $awayTeam): array;

    /**
     * @return array<string,mixed>
     */
    protected function partialGameAttributes(GameData $dto, array $rawGame, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $dateParts = GameData::extractDateParts($rawGame['date'] ?? null);
        [$homeCompetitor, $awayCompetitor] = $this->resolveCompetitors($rawGame);

        return [
            'espn_event_id' => $dto->espnEventId,
            'espn_uid' => $rawGame['uid'] ?? null,
            'season' => $dto->season,
            'week' => $dto->week,
            'season_type' => $dto->seasonType,
            'game_date' => $dateParts['game_date'],
            'game_time' => $dateParts['game_time'],
            'name' => $dto->name,
            'short_name' => $dto->shortName,
            'home_team_id' => $homeTeam?->getKey(),
            'away_team_id' => $awayTeam?->getKey(),
            'home_team_display_name' => $this->competitorDisplayName($homeCompetitor),
            'away_team_display_name' => $this->competitorDisplayName($awayCompetitor),
            'home_team_abbreviation' => $this->competitorAbbreviation($homeCompetitor),
            'away_team_abbreviation' => $this->competitorAbbreviation($awayCompetitor),
            'home_score' => $dto->homeScore,
            'away_score' => $dto->awayScore,
            'home_linescores' => $dto->homeLinescores,
            'away_linescores' => $dto->awayLinescores,
            'status' => $dto->status,
            'period' => $dto->period,
            'game_clock' => $dto->gameClock,
            'venue_name' => $dto->venueName,
            'venue_city' => $dto->venueCity,
            'venue_state' => $dto->venueState,
            'broadcast_networks' => $dto->broadcastNetworks,
            ...$this->extraPartialGameAttributes($dto, $rawGame, $homeTeam, $awayTeam),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function extraPartialGameAttributes(GameData $dto, array $rawGame, ?Model $homeTeam, ?Model $awayTeam): array
    {
        return [];
    }

    /**
     * @return array<string,mixed>
     */
    protected function existingGameAttributes(
        GameData $dto,
        array $rawGame,
        Model $homeTeam,
        Model $awayTeam,
        Model $existingGame
    ): array {
        return $this->gameAttributes($dto, $rawGame, $homeTeam, $awayTeam);
    }

    /**
     * @return array<string,mixed>
     */
    protected function gameLookupAttributes(GameData $dto): array
    {
        return ['espn_event_id' => $dto->espnEventId];
    }

    protected function shouldUpdateExistingGame(Model $existingGame, GameData $dto, array $rawGame): bool
    {
        return true;
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        if (static::GAME_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('GAME_MODEL_CLASS must be defined.');
        }

        return static::GAME_MODEL_CLASS;
    }

    /**
     * @param  array<string, mixed>  $rawGame
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function resolveCompetitors(array $rawGame): array
    {
        $competition = $rawGame['competitions'][0] ?? data_get($rawGame, 'header.competitions.0', []);
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
     * @param  array<string,mixed>  $attributes
     */
    protected function shouldStorePartialGame(array $attributes): bool
    {
        return (bool) ($attributes['is_ncaa_tournament'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $attributes
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
}
