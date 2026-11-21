<?php

namespace App\Console\Commands\Sports;

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\NBA\Injuries\InjuryCanaryScope;
use App\Services\Research\GameResearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshNbaInjuryResearchCommand extends Command
{
    protected $signature = 'nba:refresh-injury-research {game : NBA game ID}';

    protected $description = 'Assess stored NBA evidence only; no acquisition, paid review or queued dispatch';

    public function handle(GameResearchService $research): int
    {
        if (! config('nba.injuries.research_adapter_enabled', false) || ! $research->enabled('nba')) {
            $this->error('NBA structured research is disabled.');

            return self::FAILURE;
        }
        $game = Game::query()->findOrFail($this->argument('game'));
        if (! app(InjuryCanaryScope::class)->allowsGame($game)) {
            $this->error('NBA game is outside the selected, unexpired team canary scope.');

            return self::FAILURE;
        }
        $result = DB::transaction(function () use ($game, $research): array {
            // Match the acquisition lock order, and prevent source-head changes during assessment.
            Team::query()->whereIn('id', [$game->home_team_id, $game->away_team_id])->orderBy('id')->lockForUpdate()->get();

            return $research->assess($game, 'nba');
        });
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
