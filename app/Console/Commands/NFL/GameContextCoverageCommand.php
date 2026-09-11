<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use Illuminate\Console\Command;

class GameContextCoverageCommand extends Command
{
    protected $signature = 'nfl:game-context-coverage {--from-season=2023} {--to-season=} {--game=}';

    protected $description = 'Report historical context evidence and missing coverage without assuming healthy players';

    public function handle(): int
    {
        $query = Game::query()->whereBetween('season', [(int) $this->option('from-season'), (int) ($this->option('to-season') ?: now()->year)]);
        if ($this->option('game')) {
            $query->whereKey($this->option('game'));
        }
        $games = $query->with('contextFacts')->get();
        if ($this->option('game')) {
            $this->line($games->map(fn ($game) => [
                'game_id' => $game->id,
                'coverage' => 'partial_evidence_only',
                'facts' => $game->contextFacts,
            ])->toJson(JSON_PRETTY_PRINT));
        } else {
            $rows = [];
            foreach ($games->groupBy(fn ($game) => $game->season.' / '.$game->season_type) as $season => $group) {
                $coaches = $injuries = $missing = 0;
                foreach ($group as $game) {
                    foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
                        $facts = $game->contextFacts->where('team_id', $teamId);
                        $coaches += (int) $facts->contains('kind', 'head_coach');
                        $hasInjuries = $facts->contains('kind', 'injury_report');
                        $injuries += (int) $hasInjuries;
                        $missing += (int) ! $hasInjuries;
                    }
                }
                $rows[] = [$season, $group->count(), $coaches, $injuries, $missing];
            }
            $this->table(['Season / type', 'Games', 'Team-games with coach', 'With injury evidence', 'No injury evidence'], $rows);
        }
        $this->warn('Evidence counts do not establish complete injury coverage. No evidence means unknown.');

        return self::SUCCESS;
    }
}
