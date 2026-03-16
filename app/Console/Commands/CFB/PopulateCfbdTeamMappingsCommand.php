<?php

namespace App\Console\Commands\CFB;

use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use Illuminate\Console\Command;

class PopulateCfbdTeamMappingsCommand extends Command
{
    protected $signature = 'cfbd:populate-team-mappings';

    protected $description = 'Fetch FBS teams from CollegeFootballData and populate the mapping table';

    public function handle(CollegeFootballDataService $service): int
    {
        $this->info('Fetching FBS teams from CollegeFootballData...');

        $teams = $service->getFbsTeams();

        if ($teams === []) {
            $this->error('No teams were returned from CollegeFootballData.');

            return Command::FAILURE;
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($teams as $team) {
            $cfbdTeamId = (int) ($team['id'] ?? 0);
            $school = trim((string) ($team['school'] ?? ''));

            if ($cfbdTeamId <= 0 || $school === '') {
                $skipped++;

                continue;
            }

            $mapping = CfbdTeamMapping::query()->firstOrNew([
                'cfbd_team_id' => $cfbdTeamId,
            ]);

            if ($mapping->exists) {
                $updated++;
            } else {
                $added++;
            }

            $mapping->fill([
                'cfbd_team_name' => $school,
                'cfbd_abbreviation' => $team['abbreviation'] ?? null,
                'sport' => 'americanfootball_ncaaf',
                'conference' => $team['conference'] ?? null,
                'division' => $team['division'] ?? null,
                'alternate_names' => $team['alternateNames'] ?? [],
            ]);
            $mapping->save();
        }

        $this->info("Added {$added} new teams, updated {$updated} existing teams, skipped {$skipped} invalid rows.");

        return Command::SUCCESS;
    }
}
