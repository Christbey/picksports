<?php

namespace App\Console\Commands\CBB;

use App\Models\OddsApiTeamMapping;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Console\Command;

class PopulateOddsApiTeamMappingsCommand extends Command
{
    protected $signature = 'cbb:populate-odds-api-teams';

    protected $description = 'Fetch all team participants from The Odds API and populate the mappings table';

    public function handle(OddsApiService $oddsApiService): int
    {
        $this->info('Fetching participants from The Odds API...');

        $participants = $oddsApiService->getParticipants('basketball_ncaab');

        if (! $participants) {
            $this->error('Failed to fetch participants from The Odds API');

            return Command::FAILURE;
        }

        $this->info('Found '.count($participants).' teams');

        $added = 0;
        $skipped = 0;

        foreach ($participants as $participant) {
            $teamName = $participant['full_name'] ?? null;

            if (! $teamName) {
                continue;
            }

            $existing = OddsApiTeamMapping::query()
                ->where('odds_api_team_name', $teamName)
                ->where('sport', 'basketball_ncaab')
                ->first();

            if ($existing) {
                $skipped++;

                continue;
            }

            OddsApiTeamMapping::create([
                'espn_team_name' => null,
                'odds_api_team_name' => $teamName,
                'sport' => 'basketball_ncaab',
            ]);

            $added++;
        }

        $this->info("Added {$added} new teams, skipped {$skipped} existing teams");

        return Command::SUCCESS;
    }
}
