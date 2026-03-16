<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use App\Services\SportsAssetStorage;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = '';

    protected const TEAM_MODEL_CLASS = '';

    protected const PLAYER_DTO_CLASS = '';

    protected const ATHLETES_NESTED_UNDER_GROUP_ITEMS = false;

    public function __construct(
        protected BaseEspnService $espnService,
        protected ?SportsAssetStorage $sportsAssetStorage = null,
    ) {
        $this->sportsAssetStorage ??= app(SportsAssetStorage::class);
    }

    protected function getAthletesKey(): string
    {
        return 'athletes';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    protected function extractAthletes(array $response): array
    {
        $athletes = $response[$this->getAthletesKey()] ?? null;

        $athleteItems = is_array($athletes) ? $athletes : [];

        if (! $this->athletesNestedUnderGroupItems()) {
            return $athleteItems;
        }

        $flattened = [];
        foreach ($athleteItems as $group) {
            if (isset($group['items']) && is_array($group['items'])) {
                array_push($flattened, ...$group['items']);
            }
        }

        return $flattened;
    }

    protected function findTeamByEspnId(string $teamEspnId): ?Model
    {
        $teamModel = $this->teamModelClass();

        return $teamModel::query()->where('espn_id', $teamEspnId)->first();
    }

    public function execute(string $teamEspnId): int
    {
        $response = $this->espnService->getRoster($teamEspnId);
        if (! is_array($response)) {
            return 0;
        }

        $athletes = $this->extractAthletes($response);
        if ($athletes === []) {
            return 0;
        }

        $team = $this->findTeamByEspnId($teamEspnId);
        if (! $team) {
            return 0;
        }

        $playerModel = $this->playerModelClass();
        $synced = 0;

        foreach ($athletes as $athleteData) {
            if (empty($athleteData['id'])) {
                continue;
            }

            $dto = $this->playerDtoFromAthlete($athleteData);
            $attributes = $dto->toArray();
            $attributes['team_id'] = $team->getKey();
            $attributes['headshot_url'] = $this->mirrorHeadshot(
                $attributes['headshot_url'] ?? null,
                $this->sportKey(),
                $this->teamAssetIdentifier($team),
                $this->playerAssetIdentifier($attributes, (string) $dto->espnId)
            );

            if ($playerModel::query()->getModel()->isFillable('headshot')) {
                $attributes['headshot'] = $attributes['headshot_url'];
            }

            $playerModel::updateOrCreate(
                ['espn_id' => $dto->espnId],
                $attributes
            );

            $synced++;
        }

        return $synced;
    }

    public function syncAllTeams(): int
    {
        $teamModel = $this->teamModelClass();
        $teams = $teamModel::query()->get(['espn_id']);
        $totalSynced = 0;

        foreach ($teams as $team) {
            $totalSynced += $this->execute((string) $team->espn_id);
        }

        return $totalSynced;
    }

    /**
     * @param  array<string, mixed>  $athleteData
     */
    protected function playerDtoFromAthlete(array $athleteData): object
    {
        $playerDtoClass = $this->playerDtoClass();

        return $playerDtoClass::fromEspnResponse($athleteData);
    }

    /**
     * @return class-string<Model>
     */
    protected function playerModelClass(): string
    {
        if (static::PLAYER_MODEL_CLASS === '') {
            throw new \RuntimeException('PLAYER_MODEL_CLASS must be defined.');
        }

        return static::PLAYER_MODEL_CLASS;
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
    protected function playerDtoClass(): string
    {
        if (static::PLAYER_DTO_CLASS === '') {
            throw new \RuntimeException('PLAYER_DTO_CLASS must be defined.');
        }

        return static::PLAYER_DTO_CLASS;
    }

    protected function athletesNestedUnderGroupItems(): bool
    {
        return static::ATHLETES_NESTED_UNDER_GROUP_ITEMS;
    }

    protected function mirrorHeadshot(?string $sourceUrl, string $sport, string $teamIdentifier, string $playerIdentifier): ?string
    {
        return $this->sportsAssetStorage?->mirrorPlayerHeadshot($sourceUrl, $sport, $teamIdentifier, $playerIdentifier) ?? $sourceUrl;
    }

    protected function sportKey(): string
    {
        $namespace = static::class;
        $segments = explode('\\', $namespace);

        return strtolower($segments[3] ?? 'sports');
    }

    protected function teamAssetIdentifier(Model $team): string
    {
        $name = trim(implode(' ', array_filter([
            $team->getRawOriginal('location') ?? $team->getRawOriginal('school') ?? null,
            $team->getRawOriginal('name') ?? $team->getRawOriginal('mascot') ?? null,
        ])));
        $id = (string) ($team->espn_id ?: $team->getKey());

        return $name !== '' ? "{$name}-{$id}" : $id;
    }

    protected function playerAssetIdentifier(array $attributes, string $fallbackId): string
    {
        $name = trim((string) (
            $attributes['full_name']
            ?? $attributes['display_name']
            ?? $attributes['name']
            ?? trim(implode(' ', array_filter([
                $attributes['first_name'] ?? null,
                $attributes['last_name'] ?? null,
            ])))
        ));

        return $name !== '' ? "{$name}-{$fallbackId}" : $fallbackId;
    }
}
