<?php

namespace App\Console\Commands\Sports;

use App\Models\BetDecision;
use App\Models\ModelArtifact;
use Illuminate\Console\Command;

class ReportModelFeedbackCommand extends Command
{
    protected $signature = 'sports:report-model-feedback {artifact : Model artifact UUID}';

    protected $description = 'Report settled ROI, counterfactual ROI, CLV, calibration, and no-bet reasons';

    public function handle(): int
    {
        $artifact = ModelArtifact::query()->findOrFail((string) $this->argument('artifact'));
        $decisions = BetDecision::query()
            ->with(['settlement', 'shadowOutput'])
            ->where('model_artifact_id', $artifact->id)
            ->get();
        $settled = $decisions->filter(fn (BetDecision $decision): bool => $decision->settlement !== null);
        $bets = $settled->filter(fn (BetDecision $decision): bool => $decision->is_bet);
        $actualProfit = (float) $bets->sum(fn (BetDecision $decision): float => (float) $decision->settlement?->profit_units);
        $shadowProfit = (float) $settled->sum(
            fn (BetDecision $decision): float => (float) data_get($decision->settlement?->metadata, 'shadow_profit_units', 0.0)
        );
        $brierRows = $settled->filter(fn (BetDecision $decision): bool => $decision->shadowOutput !== null
            && (float) $decision->settlement->result_value !== 0.0);
        $clvRows = $settled->filter(
            fn (BetDecision $decision): bool => $decision->settlement?->clv !== null
        );
        $brier = $brierRows->isEmpty() ? null : $brierRows->avg(function (BetDecision $decision): float {
            $homeProbability = (float) $decision->shadowOutput->challenger_output;
            $homeWon = (float) $decision->settlement->result_value > 0 ? 1.0 : 0.0;

            return ($homeProbability - $homeWon) ** 2;
        });
        $noBetReasons = $decisions
            ->where('is_bet', false)
            ->flatMap(fn (BetDecision $decision): array => (array) $decision->eligibility_reasons)
            ->countBy()
            ->sortDesc();

        $this->info('Model Settlement Feedback');
        $this->line('Artifact: '.$artifact->id);
        $this->table(
            ['Metric', 'Value'],
            [
                ['Decisions', (string) $decisions->count()],
                ['Settled decisions', (string) $settled->count()],
                ['Tracked bets', (string) $bets->count()],
                ['Actual ROI', $bets->isEmpty() ? 'N/A' : number_format($actualProfit / $bets->count() * 100, 2).'%'],
                ['Counterfactual ROI', $settled->isEmpty() ? 'N/A' : number_format($shadowProfit / $settled->count() * 100, 2).'%'],
                ['Average CLV probability', $clvRows->isEmpty()
                    ? 'N/A'
                    : number_format((float) $clvRows->avg(fn (BetDecision $decision): ?float => $decision->settlement?->clv), 4)],
                ['Challenger Brier', $brier === null ? 'N/A' : number_format((float) $brier, 4)],
            ],
        );

        if ($noBetReasons->isNotEmpty()) {
            $this->newLine();
            $this->info('Why The Model Did Not Bet');
            $this->table(
                ['Reason', 'Count'],
                $noBetReasons->map(fn (int $count, string $reason): array => [$reason, (string) $count])->values()->all(),
            );
        }

        return self::SUCCESS;
    }
}
