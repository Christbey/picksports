<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Services\CFB\TrueEpaCalculator;
use Illuminate\Console\Command;

class CalculatePlayEpaCommand extends Command
{
    protected $signature = 'cfb:calculate-play-epa
        {--season= : Limit to season (e.g. 2025)}
        {--game_id= : Limit to a single cfb_games.id}
        {--limit=0 : Limit number of games (0 = all)}
        {--rebuild : Recalculate for all matching plays, including previously scored rows}
        {--dry-run : Preview updates without writing}';

    protected $description = 'Calculate true play-by-play EPA for CFB plays';

    public function handle(TrueEpaCalculator $calculator): int
    {
        $season = $this->option('season');
        $gameId = $this->option('game_id');
        $limit = max(0, (int) $this->option('limit'));
        $rebuild = (bool) $this->option('rebuild');
        $dryRun = (bool) $this->option('dry-run');

        $query = Game::query()
            ->whereHas('plays')
            ->orderByDesc('game_date');

        if ($season !== null && $season !== '') {
            $query->where('season', (int) $season);
        }

        if ($gameId !== null && $gameId !== '') {
            $query->where('id', (int) $gameId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $games = $query->get(['id', 'home_team_id', 'away_team_id', 'season']);
        if ($games->isEmpty()) {
            $this->warn('No matching games found.');

            return self::SUCCESS;
        }

        $this->info("Calculating true EPA for {$games->count()} game(s)...".($dryRun ? ' [dry-run]' : ''));
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $playsUpdated = 0;
        $eligibleUpdated = 0;

        foreach ($games as $game) {
            $plays = Play::query()
                ->where('game_id', $game->id)
                ->orderBy('sequence_number')
                ->orderBy('id')
                ->get([
                    'id',
                    'game_id',
                    'play_type',
                    'play_text',
                    'down',
                    'distance',
                    'yards_to_endzone',
                    'yards_gained',
                    'home_score',
                    'away_score',
                    'possession_team_id',
                    'true_epa',
                ]);

            if ($plays->isEmpty()) {
                $bar->advance();

                continue;
            }

            $results = $calculator->calculateForGame(
                $plays,
                (int) $game->home_team_id,
                (int) $game->away_team_id,
                isset($game->season) ? (int) $game->season : null
            );

            foreach ($plays as $play) {
                $result = $results[(int) $play->id] ?? null;
                if ($result === null) {
                    continue;
                }

                $payload = [
                    'is_epa_eligible' => $result['eligible'],
                    'expected_points_before' => $result['ep_before'],
                    'expected_points_after' => $result['ep_after'],
                    'true_epa' => $result['epa'],
                ];

                if (! $rebuild && $play->true_epa !== null) {
                    continue;
                }

                $playsUpdated++;
                if ($result['eligible']) {
                    $eligibleUpdated++;
                }

                if (! $dryRun) {
                    Play::query()->where('id', $play->id)->update($payload);
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Dry run complete. Plays to update: {$playsUpdated}; eligible: {$eligibleUpdated}");
        } else {
            $this->info("EPA calculation complete. Plays updated: {$playsUpdated}; eligible: {$eligibleUpdated}");
        }

        return self::SUCCESS;
    }
}
