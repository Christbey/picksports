<?php

namespace App\Console\Commands;

use App\Models\OddsApiTeamMapping;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Console\Command;

class PopulateOddsApiTeamMappingsCommand extends Command
{
    protected $signature = 'odds:populate-team-mappings {sport?} {--all : Populate all supported sports}';

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
        $sports = $this->resolveSports();

        if ($sports === []) {
            $this->error('Invalid sport. Valid options: '.implode(', ', array_keys($this->validSports)).' or use --all');

            return Command::FAILURE;
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($sports as $sport) {
            $this->info("Fetching {$this->validSports[$sport]} participants from The Odds API...");

            $participants = $oddsApiService->getParticipants($sport);

            if (! $participants) {
                $this->error("Failed to fetch participants from The Odds API for [{$sport}]");

                return Command::FAILURE;
            }

            $this->info('Found '.count($participants).' teams');

            foreach ($participants as $participant) {
                $teamName = $participant['full_name'] ?? null;
                $teamId = $participant['id'] ?? null;

                if (! $teamName) {
                    $skipped++;

                    continue;
                }

                $existing = OddsApiTeamMapping::query()
                    ->where('sport', $sport)
                    ->where(function ($query) use ($teamId, $teamName): void {
                        if ($teamId) {
                            $query->where('odds_api_team_id', $teamId);
                        }

                        $query->orWhere('odds_api_team_name', $teamName);
                    })
                    ->first();

                if ($existing) {
                    $existing->update([
                        'odds_api_team_name' => $teamName,
                        'odds_api_team_id' => $teamId,
                    ]);
                    $updated++;

                    continue;
                }

                OddsApiTeamMapping::create([
                    'espn_team_name' => null,
                    'odds_api_team_name' => $teamName,
                    'odds_api_team_id' => $teamId,
                    'sport' => $sport,
                ]);

                $added++;
            }
        }

        $this->info("Added {$added} new teams, updated {$updated} existing teams, skipped {$skipped} invalid rows");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveSports(): array
    {
        if ($this->option('all')) {
            return array_keys($this->validSports);
        }

        $sport = $this->argument('sport');

        if (! is_string($sport) || ! isset($this->validSports[$sport])) {
            return [];
        }

        return [$sport];
    }
}
