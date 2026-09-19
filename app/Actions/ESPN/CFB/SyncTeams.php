<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractCollegeSyncTeams;
use App\DataTransferObjects\ESPN\CollegeTeamData;
use App\Models\CFB\Team;

class SyncTeams extends AbstractCollegeSyncTeams
{
    protected const SPORT_LABEL = 'CFB';

    protected const CONFERENCE_API_BASE_URL = 'https://sports.core.api.espn.com/v2/sports/football/leagues/college-football/groups';

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_DTO_CLASS = CollegeTeamData::class;

    /** Resolve conference children (e.g. Sun Belt East) up to the actual subdivision. */
    protected function resolveConferenceDivision(array $team): array
    {
        $group = $team['groups'] ?? $team['group'] ?? null;
        $names = [];
        $seen = [];
        for ($depth = 0; is_array($group) && $depth < 8; $depth++) {
            $id = $this->resolveGroupId($group);
            $ref = (string) ($group['$ref'] ?? '');
            $key = $ref ?: ($id ?? json_encode($group));
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;
            // Fetch complete groups: named inline children can still omit grandparents.
            if ($ref !== '') {
                $payload = $this->resolveRefPayload($ref);
                if (is_array($payload)) {
                    $group = array_replace_recursive($payload, $group);
                }
            } elseif ($id !== null && ! isset($group['parent'])) {
                $url = $this->getConferenceApiBaseUrl().'/'.$id;
                $payload = $this->resolveRefPayload($url);
                if (is_array($payload)) {
                    $group = array_replace_recursive($payload, $group);
                }
            }
            if (isset($group['name'])) {
                $names[] = $group['name'];
            }
            $group = $group['parent'] ?? null;
        }
        foreach ($names as $index => $name) {
            $subdivision = match (strtoupper(trim($name))) {
                'FBS', 'BOWL SUBDIVISION', 'FOOTBALL BOWL SUBDIVISION' => 'FBS',
                'FCS', 'CHAMPIONSHIP SUBDIVISION', 'FOOTBALL CHAMPIONSHIP SUBDIVISION' => 'FCS',
                default => null,
            };
            if ($subdivision !== null) {
                return [$index > 0 ? $names[$index - 1] : ($team['conference']['name'] ?? null), $subdivision];
            }
        }

        return parent::resolveConferenceDivision($team);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    protected function extractTeams(array $response): array
    {
        $items = $response['items'] ?? null;

        if (! is_array($items)) {
            return parent::extractTeams($response);
        }

        return collect($items)
            ->map(function ($item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $ref = (string) ($item['$ref'] ?? '');
                if ($ref !== '' && preg_match('/\/teams\/(\d+)(?:\?|\/|$)/', $ref, $matches) === 1) {
                    return ['team' => ['id' => $matches[1]]];
                }

                $id = $item['id'] ?? null;
                if (is_scalar($id) && (string) $id !== '') {
                    return ['team' => ['id' => (string) $id]];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
