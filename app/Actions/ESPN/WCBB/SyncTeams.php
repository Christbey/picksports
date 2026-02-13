<?php

namespace App\Actions\ESPN\WCBB;

use App\DataTransferObjects\ESPN\CollegeTeamData;
use App\Models\WCBB\Team;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncTeams
{
    public function __construct(
        protected EspnService $espnService
    ) {}

    public function execute(): int
    {
        $response = $this->espnService->getTeams();

        if (! $response || ! isset($response['sports'][0]['leagues'][0]['teams'])) {
            return 0;
        }

        $teams = $response['sports'][0]['leagues'][0]['teams'];
        $synced = 0;

        // Cache conference and division data to avoid repeated API calls
        $conferenceCache = [];
        $divisionCache = [];

        foreach ($teams as $teamData) {
            $team = $teamData['team'] ?? [];

            if (empty($team['id'])) {
                continue;
            }

            // Fetch detailed team info to get conference
            try {
                $teamDetail = $this->espnService->getTeam($team['id']);
                $detailedTeam = $teamDetail['team'] ?? $team;
            } catch (ConnectionException $e) {
                Log::warning("WCBB: Skipping team {$team['id']} due to connection timeout", [
                    'team_id' => $team['id'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Get conference and division names if available
            $conferenceName = null;
            $divisionName = null;

            if (isset($detailedTeam['groups']['id'])) {
                $conferenceId = $detailedTeam['groups']['id'];

                if (isset($conferenceCache[$conferenceId])) {
                    $conferenceName = $conferenceCache[$conferenceId]['name'];
                    $divisionName = $conferenceCache[$conferenceId]['division'];
                } else {
                    $conferenceName = $this->fetchConferenceName($conferenceId);

                    // Get division from parent if available
                    if (isset($detailedTeam['groups']['parent']['id'])) {
                        $divisionId = $detailedTeam['groups']['parent']['id'];
                        if (isset($divisionCache[$divisionId])) {
                            $divisionName = $divisionCache[$divisionId];
                        } else {
                            $divisionName = $this->fetchDivisionNameById($divisionId);
                            $divisionCache[$divisionId] = $divisionName;
                        }
                    }

                    $conferenceCache[$conferenceId] = [
                        'name' => $conferenceName,
                        'division' => $divisionName,
                    ];
                }
            }

            $dto = CollegeTeamData::fromEspnResponse($detailedTeam);

            $teamAttributes = $dto->toArray();
            if ($conferenceName) {
                $teamAttributes['conference'] = $conferenceName;
            }
            if ($divisionName) {
                $teamAttributes['division'] = $divisionName;
            }

            Team::updateOrCreate(
                ['espn_id' => $dto->espnId],
                $teamAttributes
            );

            $synced++;
        }

        return $synced;
    }

    protected function fetchConferenceName(string $conferenceId): ?string
    {
        $url = "https://sports.core.api.espn.com/v2/sports/basketball/leagues/womens-college-basketball/groups/{$conferenceId}";

        $response = Http::timeout(10)->get($url);

        if ($response->successful()) {
            $data = $response->json();

            return $data['name'] ?? null;
        }

        return null;
    }

    protected function fetchDivisionNameById(string $divisionId): ?string
    {
        $url = "https://sports.core.api.espn.com/v2/sports/basketball/leagues/womens-college-basketball/groups/{$divisionId}";

        $response = Http::timeout(10)->get($url);

        if ($response->successful()) {
            $data = $response->json();

            return $data['name'] ?? null;
        }

        return null;
    }
}
