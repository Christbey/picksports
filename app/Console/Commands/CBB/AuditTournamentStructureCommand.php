<?php

namespace App\Console\Commands\CBB;

use App\Models\CBB\Game;
use Illuminate\Console\Command;

class AuditTournamentStructureCommand extends Command
{
    private const REGIONS = ['East', 'West', 'South', 'Midwest'];

    private const ROUND_OF_64_SEED_PAIRINGS = [
        [1, 16],
        [8, 9],
        [5, 12],
        [4, 13],
        [6, 11],
        [3, 14],
        [7, 10],
        [2, 15],
    ];

    protected $signature = 'cbb:audit-tournament-structure
                            {--season= : Restrict to a season}';

    protected $description = 'Audit NCAA tournament structure completeness for CBB games';

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: config('cbb.season.default'));
        $games = Game::query()
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->get();

        $issues = [];

        $firstFourCount = $games->where('tournament_round', 'first_four')->count();
        if ($firstFourCount !== 4) {
            $issues[] = "Expected 4 First Four games, found {$firstFourCount}.";
        }

        foreach (self::REGIONS as $region) {
            $roundOf64 = $games->where('tournament_region', $region)->where('tournament_round', 'round_of_64');
            if ($roundOf64->count() !== 8) {
                $issues[] = "{$region}: expected 8 Round of 64 games, found {$roundOf64->count()}.";
            }

            foreach (self::ROUND_OF_64_SEED_PAIRINGS as [$seedA, $seedB]) {
                $match = $roundOf64->first(function (Game $game) use ($seedA, $seedB) {
                    $homeSeed = (int) ($game->home_seed ?? 0);
                    $awaySeed = (int) ($game->away_seed ?? 0);

                    return [$homeSeed, $awaySeed] === [$seedA, $seedB]
                        || [$homeSeed, $awaySeed] === [$seedB, $seedA];
                });

                if (! $match) {
                    $issues[] = "{$region}: missing Round of 64 seed pairing {$seedA}/{$seedB}.";
                }
            }
        }

        if ($issues === []) {
            $this->info("Tournament structure audit passed for {$season}.");

            return self::SUCCESS;
        }

        $this->error("Tournament structure audit failed for {$season}:");
        foreach ($issues as $issue) {
            $this->line("- {$issue}");
        }

        return self::FAILURE;
    }
}
