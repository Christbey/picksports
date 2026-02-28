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

        $detailedTeam = is_array($teamDetail['team'] ?? null) ? $teamDetail['team'] : $team;
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
        $conferenceName = null;
        $divisionName = null;

        if (! isset($team['groups']['id'])) {
            return [$conferenceName, $divisionName];
        }

        $conferenceId = (string) $team['groups']['id'];

        if (isset($this->conferenceCache[$conferenceId])) {
            return [
                $this->conferenceCache[$conferenceId]['name'],
                $this->conferenceCache[$conferenceId]['division'],
            ];
        }

        $conferenceName = $this->fetchGroupName($conferenceId);
        if (isset($team['groups']['parent']['id'])) {
            $divisionId = (string) $team['groups']['parent']['id'];
            if (array_key_exists($divisionId, $this->divisionCache)) {
                $divisionName = $this->divisionCache[$divisionId];
            } else {
                $divisionName = $this->fetchGroupName($divisionId);
                $this->divisionCache[$divisionId] = $divisionName;
            }
        }

        $this->conferenceCache[$conferenceId] = [
            'name' => $conferenceName,
            'division' => $divisionName,
        ];

        return [$conferenceName, $divisionName];
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
