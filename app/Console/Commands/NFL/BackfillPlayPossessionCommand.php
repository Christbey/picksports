<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Services\NFL\PlayEpaDataService;
use Illuminate\Console\Command;

class BackfillPlayPossessionCommand extends Command
{
    protected $signature = 'nfl:backfill-play-possession
        {--season= : Limit to season (e.g. 2025)}
        {--game_id= : Limit to a single nfl_games.id}
        {--limit=0 : Limit number of games (0 = all)}
        {--dry-run : Preview updates without writing}';

    protected $description = 'Backfill nfl_plays.possession_team_id using play text + home/away roster inference';

    public function handle(PlayEpaDataService $playDataService): int
    {
        $season = $this->option('season');
        $gameId = $this->option('game_id');
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Game::query()
            ->with(['homeTeam:id,abbreviation', 'awayTeam:id,abbreviation'])
            ->whereHas('plays');

        if ($season !== null && $season !== '') {
            $query->where('season', (int) $season);
        }

        if ($gameId !== null && $gameId !== '') {
            $query->where('id', (int) $gameId);
        }

        $query->orderByDesc('game_date');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $games = $query->get();
        if ($games->isEmpty()) {
            $this->warn('No matching games found.');

            return self::SUCCESS;
        }

        $this->info("Scanning {$games->count()} game(s)...".($dryRun ? ' [dry-run]' : ''));
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $updated = 0;
        $candidates = 0;

        foreach ($games as $game) {
            if (! $game->homeTeam || ! $game->awayTeam) {
                $bar->advance();

                continue;
            }

            $lastNameTeamMap = $playDataService->buildLastNameTeamMap($game->homeTeam, $game->awayTeam);
            $plays = Play::query()
                ->where('game_id', $game->id)
                ->orderBy('sequence_number')
                ->orderBy('id')
                ->get([
                    'id',
                    'play_type',
                    'play_text',
                    'down',
                    'distance',
                    'yards_to_endzone',
                    'is_turnover',
                    'possession_team_id',
                ]);

            $lastKnownPossession = null;

            foreach ($plays as $play) {
                if ($play->possession_team_id !== null) {
                    $lastKnownPossession = (int) $play->possession_team_id;

                    continue;
                }

                $inferredTeamId = $playDataService->inferPossessionFromPlayText(
                    (string) ($play->play_text ?? ''),
                    (string) ($play->play_type ?? ''),
                    $lastNameTeamMap,
                    (int) $game->home_team_id,
                    (int) $game->away_team_id
                );

                if ($inferredTeamId === null && $lastKnownPossession !== null && $playDataService->isEpaEligiblePlay($play)) {
                    $inferredTeamId = $lastKnownPossession;
                }

                if ($inferredTeamId === null) {
                    continue;
                }

                $lastKnownPossession = $inferredTeamId;

                $candidates++;
                if (! $dryRun) {
                    Play::query()
                        ->where('id', $play->id)
                        ->update(['possession_team_id' => $inferredTeamId]);
                }

                if ((bool) $play->is_turnover) {
                    $lastKnownPossession = $lastKnownPossession === (int) $game->home_team_id
                        ? (int) $game->away_team_id
                        : (int) $game->home_team_id;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $updated = $dryRun ? 0 : $candidates;
        if ($dryRun) {
            $this->info("Dry run complete. Candidate possession updates: {$candidates}");
        } else {
            $this->info("Backfill complete. Updated possession_team_id rows: {$updated}");
        }

        return self::SUCCESS;
    }
}
