<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;

abstract class AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = '';

    protected const TEAM_DTO_CLASS = '';

    public function __construct(protected BaseEspnService $espnService) {}

    /**
     * @param  array<string, mixed>  $team
     */
    protected function resolveTeam(array $team): ?array
    {
        return $team;
    }

    /**
     * @param  array<string, mixed>  $resolvedTeam
     * @param  array<string, mixed>  $rawTeam
     * @return array<string, mixed>
     */
    protected function mapTeamAttributes(object $dto, array $resolvedTeam, array $rawTeam): array
    {
        return $dto->toArray();
    }

    protected function getUniqueKey(): string
    {
        return 'espn_id';
    }

    protected function getDtoEspnId(object $dto): string
    {
        return (string) $dto->espnId;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    protected function extractTeams(array $response): array
    {
        $teams = $response['sports'][0]['leagues'][0]['teams'] ?? null;

        return is_array($teams) ? $teams : [];
    }

    public function execute(): int
    {
        $response = $this->espnService->getTeams();
        if (! is_array($response)) {
            return 0;
        }

        $teams = $this->extractTeams($response);
        if ($teams === []) {
            return 0;
        }

        $teamModel = $this->teamModelClass();
        $uniqueKey = $this->getUniqueKey();
        $synced = 0;

        foreach ($teams as $teamData) {
            $rawTeam = $teamData['team'] ?? [];
            if (empty($rawTeam['id'])) {
                continue;
            }

            $resolvedTeam = $this->resolveTeam($rawTeam);
            if (! is_array($resolvedTeam) || $resolvedTeam === []) {
                continue;
            }

            $dto = $this->teamDtoFromApi($resolvedTeam);
            $espnId = $this->getDtoEspnId($dto);
            if ($espnId === '') {
                continue;
            }

            $teamModel::updateOrCreate(
                [$uniqueKey => $espnId],
                $this->mapTeamAttributes($dto, $resolvedTeam, $rawTeam)
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    protected function teamDtoFromApi(array $team): object
    {
        $teamDtoClass = $this->teamDtoClass();

        return $teamDtoClass::fromEspnResponse($team);
    }

    /**
     * @return class-string
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
    protected function teamDtoClass(): string
    {
        if (static::TEAM_DTO_CLASS === '') {
            throw new \RuntimeException('TEAM_DTO_CLASS must be defined.');
        }

        return static::TEAM_DTO_CLASS;
    }
}
