<?php

namespace App\Actions\ESPN;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractCollegeSyncTeams extends AbstractSyncTeams
{
    protected const SPORT_LABEL = '';

    protected const CONFERENCE_API_BASE_URL = '';

    /**
     * @var array<string, array{name:?string,division:?string}>
     */
    protected array $conferenceCache = [];

    /**
     * @var array<string, ?string>
     */
    protected array $divisionCache = [];

    protected function getSportLabel(): string
    {
        if (static::SPORT_LABEL === '') {
            throw new \RuntimeException('SPORT_LABEL must be defined.');
        }

        return static::SPORT_LABEL;
    }

    protected function getConferenceApiBaseUrl(): string
    {
        if (static::CONFERENCE_API_BASE_URL === '') {
            throw new \RuntimeException('CONFERENCE_API_BASE_URL must be defined.');
        }

        return static::CONFERENCE_API_BASE_URL;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    protected function resolveTeam(array $team): ?array
    {
        try {
            $teamDetail = $this->espnService->getTeam((string) $team['id']);
        } catch (ConnectionException $e) {
            Log::warning($this->getSportLabel().": Skipping team {$team['id']} due to connection timeout", [
                'team_id' => $team['id'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $detailedTeam = is_array($teamDetail['team'] ?? null)
            ? array_replace_recursive($team, $teamDetail['team'])
            : $team;
        [$conferenceName, $divisionName] = $this->resolveConferenceDivision($detailedTeam);
        $detailedTeam['__conference_name'] = $conferenceName;
        $detailedTeam['__division_name'] = $divisionName;

        return $detailedTeam;
    }

    /**
     * @param  array<string, mixed>  $resolvedTeam
     * @param  array<string, mixed>  $rawTeam
     * @return array<string, mixed>
     */
    protected function mapTeamAttributes(object $dto, array $resolvedTeam, array $rawTeam): array
    {
        $attributes = $dto->toArray();

        if (! empty($resolvedTeam['__conference_name'])) {
            $attributes['conference'] = $resolvedTeam['__conference_name'];
        }
        if (! empty($resolvedTeam['__division_name'])) {
            $attributes['division'] = $resolvedTeam['__division_name'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array{0:?string,1:?string}
     */
    protected function resolveConferenceDivision(array $team): array
    {
        $conferenceName = $team['conference']['name']
            ?? $team['groups']['name']
            ?? $team['group']['name']
            ?? null;
        $divisionName = $team['division']['name']
            ?? $team['groups']['parent']['name']
            ?? $team['group']['parent']['name']
            ?? null;

        $group = is_array($team['groups'] ?? null)
            ? $team['groups']
            : (is_array($team['group'] ?? null) ? $team['group'] : null);

        if (! is_array($group)) {
            return [$conferenceName, $divisionName];
        }

        $conferenceId = $this->resolveGroupId($group);
        if ($conferenceId === null) {
            return [$conferenceName, $divisionName];
        }

        if (isset($this->conferenceCache[$conferenceId])) {
            return [
                $this->conferenceCache[$conferenceId]['name'],
                $this->conferenceCache[$conferenceId]['division'],
            ];
        }

        if ($conferenceName === null) {
            $conferenceName = $this->fetchGroupName($conferenceId);
        }

        $parentGroup = is_array($group['parent'] ?? null) ? $group['parent'] : null;
        if ($parentGroup !== null) {
            $divisionId = $this->resolveGroupId($parentGroup);
            if ($divisionId === null) {
                $divisionName = $divisionName ?? ($parentGroup['name'] ?? null);
            } elseif (array_key_exists($divisionId, $this->divisionCache)) {
                $divisionName = $this->divisionCache[$divisionId];
            } elseif ($divisionName === null) {
                $divisionName = $this->fetchGroupName($divisionId);
                $this->divisionCache[$divisionId] = $divisionName;
            } else {
                $this->divisionCache[$divisionId] = $divisionName;
            }
        }

        $this->conferenceCache[$conferenceId] = [
            'name' => $conferenceName,
            'division' => $divisionName,
        ];

        return [$conferenceName, $divisionName];
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function resolveGroupId(array $group): ?string
    {
        $id = $group['id'] ?? null;
        if (is_scalar($id) && (string) $id !== '') {
            return (string) $id;
        }

        $ref = $group['$ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
            return null;
        }

        $trimmed = rtrim($ref, '/');
        $segment = basename($trimmed);

        return $segment !== '' ? $segment : null;
    }

    protected function fetchGroupName(string $groupId): ?string
    {
        $response = Http::timeout(10)->get($this->getConferenceApiBaseUrl()."/{$groupId}");

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? ($data['name'] ?? null) : null;
    }
}
