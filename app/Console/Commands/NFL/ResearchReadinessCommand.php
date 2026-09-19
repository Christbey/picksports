<?php

namespace App\Console\Commands\NFL;

use App\Actions\Validation\Checks\NflResearchCoverageCheck;
use App\Models\NFL\Game;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Console\Command;

class ResearchReadinessCommand extends Command
{
    protected $signature = 'nfl:research-readiness {--days-forward=2} {--json}';

    protected $description = 'Read-only readiness check for cited NFL research, linked assessments and current market prices';

    public function handle(NflResearchCoverageCheck $coverage, ResearchPipeline $pipeline): int
    {
        $result = $coverage->run('nfl', ['window_days' => max(1, min(8, (int) $this->option('days-forward')))]);
        if ($result === null) {
            $this->error('NFL research coverage could not be checked.');

            return self::FAILURE;
        }
        $staleMarkets = Game::query()->whereIn('id', $result['metadata']['eligible_game_ids'])
            ->get()->filter(fn (Game $game): bool => ! $pipeline->marketIsFresh($game))->pluck('id')->all();
        $result['metadata']['stale_market_game_ids'] = $staleMarkets;
        $ready = $result['status'] === 'passing' && $staleMarkets === [];
        $result['ready'] = $ready;
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->line($result['message']);
            $this->line('Stale/missing paired markets: '.json_encode($staleMarkets));
            $this->line('Data-held games: '.json_encode($result['metadata']['data_hold_game_ids']));
            $this->line($ready ? 'NFL research readiness: PASS (no-edge passes are valid).' : 'NFL research readiness: FAIL.');
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
