<?php

namespace App\Actions\ESPN;

use App\Services\ESPN\BaseEspnService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

abstract class AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = '';

    protected const TEAM_DTO_CLASS = '';

    /**
     * @var array<string, array<string,mixed>|null>
     */
    protected array $refCache = [];

    public function __construct(protected BaseEspnService $espnService) {}

    /**
     * @param  array<string, mixed>  $team
     */
    protected function resolveTeam(array $team): ?array
    {
        $hasConference = ! empty($team['conference']['name'])
            || ! empty($team['groups']['name'])
            || ! empty($team['group']['name']);
        $hasDivision = ! empty($team['division']['name'])
            || ! empty($team['groups']['parent']['name'])
            || ! empty($team['group']['parent']['name']);

        if ($hasConference || $hasDivision) {
            return $this->hydrateConferenceDivisionFromRefs($team);
        }

        $teamId = (string) ($team['id'] ?? '');
        if ($teamId === '') {
            return $this->hydrateConferenceDivisionFromRefs($team);
        }

        try {
            $teamDetail = $this->espnService->getTeam($teamId);
        } catch (ConnectionException $e) {
            Log::warning("ESPN: Team detail fallback timed out for team {$teamId}", [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return $team;
        }

        $detailedTeam = is_array($teamDetail['team'] ?? null) ? $teamDetail['team'] : null;
        if (! is_array($detailedTeam) || $detailedTeam === []) {
            return $this->hydrateConferenceDivisionFromRefs($team);
        }

        return $this->hydrateConferenceDivisionFromRefs(array_replace_recursive($team, $detailedTeam));
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

    /**
     * Populate conference/division from `$ref` group payloads when ESPN omits names inline.
     *
     * @param  array<string,mixed>  $team
     * @return array<string,mixed>
     */
    protected function hydrateConferenceDivisionFromRefs(array $team): array
    {
        $conference = $team['conference']['name']
            ?? $team['groups']['name']
            ?? $team['group']['name']
            ?? null;

        $division = $team['division']['name']
            ?? $team['groups']['parent']['name']
            ?? $team['group']['parent']['name']
            ?? null;

        $group = is_array($team['group'] ?? null)
            ? $team['group']
            : (is_array($team['groups'] ?? null) ? $team['groups'] : null);

        if ($conference === null && is_array($group)) {
            $conference = $group['name'] ?? null;
            if ($conference === null) {
                $groupPayload = $this->resolveRefPayload((string) ($group['$ref'] ?? ''));
                $conference = $groupPayload['name'] ?? null;
                if ($conference === null) {
                    $conference = $groupPayload['group']['name'] ?? null;
                }
                if (is_array($groupPayload['parent'] ?? null) && $division === null) {
                    $division = $groupPayload['parent']['name'] ?? $division;
                    if ($division === null) {
                        $division = $this->resolveNameFromRef((string) ($groupPayload['parent']['$ref'] ?? ''));
                    }
                }
            }
        }

        $parent = is_array($group['parent'] ?? null) ? $group['parent'] : null;
        if ($division === null && is_array($parent)) {
            $division = $parent['name'] ?? null;
            if ($division === null) {
                $division = $this->resolveNameFromRef((string) ($parent['$ref'] ?? ''));
            }
        }

        if ($conference !== null) {
            $team['conference']['name'] = $conference;
            $team['groups']['name'] = $conference;
            $team['group']['name'] = $conference;
        }

        if ($division !== null) {
            $team['division']['name'] = $division;
            $team['groups']['parent']['name'] = $division;
            $team['group']['parent']['name'] = $division;
        }

        return $team;
    }

    protected function resolveNameFromRef(string $ref): ?string
    {
        $payload = $this->resolveRefPayload($ref);
        if (! is_array($payload)) {
            return null;
        }

        return $payload['name']
            ?? $payload['group']['name']
            ?? null;
    }

    /**
     * @return array<string,mixed>|null
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

        return $this->refCache[$url] = $payload;
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
