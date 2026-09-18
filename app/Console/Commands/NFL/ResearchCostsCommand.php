<?php

namespace App\Console\Commands\NFL;

use App\Models\AiGeneration;
use App\Services\NFL\Research\ResearchSpendGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ResearchCostsCommand extends Command
{
    protected $signature = 'nfl:research-costs {--hours=24 : Lookback window, 1 to 168 hours} {--json}';

    protected $description = 'Read NFL research usage, estimates and unknown-cost reservations without calling an AI provider';

    public function handle(ResearchSpendGuard $guard): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1 || $hours > 168) {
            $this->error('--hours must be between 1 and 168.');

            return self::FAILURE;
        }
        $rows = AiGeneration::query()->where('purpose', 'nfl_game_context_research')
            ->where('started_at', '>=', now()->subHours($hours))
            ->get(['context_id', 'status', 'model', 'cost_usd', 'input_tokens', 'output_tokens', 'metadata']);
        $summarize = fn (Collection $group): array => [
            'attempts' => $group->count(),
            'statuses' => $group->countBy('status')->all(),
            'recorded_estimate_usd' => round($group->sum('cost_usd'), 6),
            'unpriced_attempts' => $group->whereNull('cost_usd')->count(),
            'unknown_cost_reservations_usd' => round($group->whereNull('cost_usd')->sum(fn ($r) => $guard->accountedCost($r)), 6),
            'budget_accounted_usd' => round($group->sum(fn ($r) => $guard->accountedCost($r)), 6),
            'recorded_web_search_calls' => $group->sum(fn ($r) => (int) data_get($r->metadata, 'web_search_calls', 0)),
            'input_tokens' => $group->sum('input_tokens'),
            'output_tokens' => $group->sum('output_tokens'),
        ];
        $report = [
            'hours' => $hours,
            'scope' => 'NFL game-context research only; estimates, not an OpenAI invoice. Unknown usage is not zero.',
            'rolling_24h_limits' => config('nfl_research.cost_control'),
            'totals' => $summarize($rows),
            'by_game' => $rows->groupBy('context_id')->map($summarize)->all(),
            'by_model' => $rows->groupBy('model')->map($summarize)->all(),
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($report['scope']);
            $this->table(['Game', 'Attempts', 'Estimated USD', 'Unpriced', 'Budget-accounted USD'],
                collect($report['by_game'])->map(fn ($r, $game) => [$game, $r['attempts'], $r['recorded_estimate_usd'], $r['unpriced_attempts'], $r['budget_accounted_usd']])->all());
            $this->line(json_encode(['hours' => $hours, ...$report['totals']]));
        }

        return self::SUCCESS;
    }
}
