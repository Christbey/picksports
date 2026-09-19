<?php

namespace App\Console\Commands\CFB;

use App\Models\BetDecision;
use App\Models\CFB\Game;
use App\Services\CFB\Predictions\CfbFrozenDecisionPerformance;
use Illuminate\Console\Command;

class ReportFrozenDecisionPerformanceCommand extends Command
{
    protected $signature = 'cfb:report-frozen-decisions {--season= : Required season}';

    protected $description = 'Read-only grading and price-adjusted returns/CLV for all frozen CFB decisions';

    public function handle(CfbFrozenDecisionPerformance $service): int
    {
        if (! ctype_digit((string) $this->option('season'))) {
            $this->error('An explicit --season is required.');

            return self::FAILURE;
        }
        $games = Game::query()->where('season', (int) $this->option('season'))->with('sportEvent')->get()->keyBy('id');
        $rows = BetDecision::query()->where('sport', 'cfb')->where('game_table', 'cfb_games')
            ->whereIn('game_id', $games->keys())->orderBy('decided_at')->orderBy('id')->get()
            ->map(fn ($decision) => $service->grade($decision, $games->get($decision->game_id)))->all();
        $summary = [];
        foreach (collect($rows)->groupBy(fn ($row) => $row['cohort'].':'.$row['market_type']) as $key => $cohort) {
            $summary[$key] = $service->summarize($cohort->all());
        }
        $largeSpreads = [];
        foreach (collect($rows)->where('market_type', 'spread')->groupBy(fn ($row) => $row['cohort'].':'.$row['large_spread_bucket']) as $key => $cohort) {
            $largeSpreads[$key] = $service->summarize($cohort->all());
        }
        $this->line(json_encode(['season' => (int) $this->option('season'), 'status' => $rows ? 'descriptive_frozen_decision_monitoring' : 'no_frozen_decisions',
            'policy' => 'All stored decisions; each revision retained, correlated observations are not independent wagers. One unit staked per priced decision. Last stored same-market/side pregame quote strictly before kickoff; never consensus or postgame imported quotes.',
            'limitations' => ['Missing decisions cannot be reconstructed retrospectively.', 'Counterfactual and tracked recommendations are not evidence of placed wagers.', 'Missing prices are excluded from return denominators, never imputed.', 'Line CLV is in points; moneyline CLV is implied probability including vig.'],
            'summary' => $summary, 'exclusive_large_spread_summary' => $largeSpreads, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
