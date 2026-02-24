<?php

namespace App\Console\Commands;

use App\Models\OddsApiTeamMapping;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Console\Command;

class PopulateOddsApiTeamMappingsCommand extends Command
{
    protected $signature = 'odds:populate-team-mappings {sport}';

    protected $description = 'Fetch all team participants from The Odds API and populate the mappings table for a given sport';

    protected array $validSports = [
        'basketball_ncaab' => 'CBB',
        'basketball_wncaab' => 'WCBB',
        'basketball_nba' => 'NBA',
        'basketball_wnba' => 'WNBA',
        'baseball_mlb' => 'MLB',
        'americanfootball_nfl' => 'NFL',
        'americanfootball_ncaaf' => 'CFB',
    ];

    public function handle(OddsApiService $oddsApiService): int
    {
        $sport = $this->argument('sport');

        if (! isset($this->validSports[$sport])) {
            $this->error('Invalid sport. Valid options: '.implode(', ', array_keys($this->validSports)));

            return Command::FAILURE;
        }

        $this->info("Fetching {$this->validSports[$sport]} participants from The Odds API...");

        $participants = $oddsApiService->getParticipants($sport);

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
                ->where('sport', $sport)
                ->first();

            if ($existing) {
                $skipped++;

                continue;
            }

            OddsApiTeamMapping::create([
                'espn_team_name' => null,
                'odds_api_team_name' => $teamName,
                'sport' => $sport,
            ]);

            $added++;
        }

        $this->info("Added {$added} new teams, skipped {$skipped} existing teams");

        return Command::SUCCESS;
    }
}
