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
