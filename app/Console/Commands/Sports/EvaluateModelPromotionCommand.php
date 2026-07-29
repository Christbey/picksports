<?php

namespace App\Console\Commands\Sports;

use App\Models\ModelArtifact;
use App\Services\ML\LiveShadowEvidenceEvaluator;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ModelPromotionEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class EvaluateModelPromotionCommand extends Command
{
    protected $signature = 'sports:evaluate-model-promotion
        {artifact : Model artifact UUID}
        {--report= : Rolling evaluation report path; defaults to the registered report}
        {--minimum-windows= : Required chronological windows}
        {--minimum-better-window-rate= : Required fraction of windows beating baseline}
        {--maximum-brier-regression= : Maximum allowed Brier regression in any window}
        {--maximum-log-loss-regression= : Maximum allowed log-loss regression in any window}
        {--maximum-mae-regression= : Maximum allowed point MAE regression in any window}
        {--minimum-live-shadow-observations= : Required live pregame-safe shadow rows per market}
        {--minimum-settled-shadow-decisions= : Required settled pregame-safe shadow decisions per market}
        {--market=* : Restrict promotion to eligible markets}
        {--promote : Promote when every gate passes}';

    protected $description = 'Evaluate a challenger against baseline across multiple chronological windows';

    public function handle(
        ModelPromotionEvaluator $evaluator,
        ModelArtifactRegistry $artifacts,
        LiveShadowEvidenceEvaluator $liveEvidence,
    ): int {
        $artifact = ModelArtifact::query()->with('trainingRun')->findOrFail((string) $this->argument('artifact'));
        $reportOption = (string) $this->option('report');

        try {
            $reportPath = $reportOption !== ''
                ? $artifacts->attachEvaluationReport($artifact, $reportOption)->evaluation_report_path
                : $artifacts->materializeEvaluationReport($artifact);
        } catch (\RuntimeException) {
            $reportPath = '';
        }

        if ($reportPath === '' || ! File::exists($reportPath)) {
            $this->error('A rolling evaluation report is required.');

            return self::FAILURE;
        }

        $decision = $evaluator->evaluate($artifact, $reportPath, array_filter([
            'minimum_windows' => $this->numericOption('minimum-windows', true),
            'minimum_better_window_rate' => $this->numericOption('minimum-better-window-rate'),
            'maximum_brier_regression' => $this->numericOption('maximum-brier-regression'),
            'maximum_log_loss_regression' => $this->numericOption('maximum-log-loss-regression'),
            'maximum_mae_regression' => $this->numericOption('maximum-mae-regression'),
        ], fn (mixed $value): bool => $value !== null));
        $requestedMarkets = collect((array) $this->option('market'))
            ->map(fn (mixed $market): string => ModelArtifact::normalizeMarketType((string) $market))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $unknownMarkets = array_values(array_diff($requestedMarkets, $decision['available_markets']));
        if ($unknownMarkets !== []) {
            $this->error('Unknown or unavailable market(s): '.implode(', ', $unknownMarkets));

            return self::FAILURE;
        }

        $eligibleMarkets = $decision['eligible_markets'];
        $marketsRequestedForPromotion = $requestedMarkets === [] ? $eligibleMarkets : $requestedMarkets;
        $liveShadowEvidence = $liveEvidence->evaluate($artifact, $marketsRequestedForPromotion, array_filter([
            'minimum_live_shadow_observations' => $this->numericOption('minimum-live-shadow-observations', true),
            'minimum_settled_shadow_decisions' => $this->numericOption('minimum-settled-shadow-decisions', true),
        ], fn (mixed $value): bool => $value !== null));
        $decision['live_shadow_evidence'] = $liveShadowEvidence;
        $decision['criteria']['live_shadow'] = $liveShadowEvidence['criteria'];
        foreach ($decision['markets'] as $market => $marketDecision) {
            $evidencePassed = (bool) data_get($liveShadowEvidence, "markets.{$market}.passed", false);
            $decision['markets'][$market]['checks']['live_shadow_evidence'] = $evidencePassed;
            $decision['markets'][$market]['promotion_ready'] = $marketDecision['eligible'] && $evidencePassed;
            $decision['checks'][$market.'.live_shadow_evidence'] = $evidencePassed;
        }
        $marketsWithLiveEvidence = array_keys(array_filter(
            (array) $liveShadowEvidence['markets'],
            fn (array $evidence): bool => (bool) $evidence['passed'],
        ));
        $allRequestedMarketsPassed = array_diff($marketsRequestedForPromotion, $eligibleMarkets) === []
            && array_diff($marketsRequestedForPromotion, $marketsWithLiveEvidence) === [];
        $newlyPromotedMarkets = (bool) $this->option('promote') && $allRequestedMarketsPassed
            ? $marketsRequestedForPromotion
            : [];
        $promotedMarkets = array_values(array_unique([
            ...$artifact->promotedMarkets(),
            ...$newlyPromotedMarkets,
        ]));
        $decision['promoted_markets'] = $promotedMarkets;
        $decision['requested_markets'] = $requestedMarkets;
        foreach ($decision['markets'] as $market => $marketDecision) {
            $decision['markets'][$market]['promoted'] = in_array($market, $promotedMarkets, true);
        }
        $shouldPromote = $newlyPromotedMarkets !== [];

        DB::transaction(function () use ($artifact, $decision, $shouldPromote, $promotedMarkets, $newlyPromotedMarkets): void {
            if ($shouldPromote) {
                ModelArtifact::query()
                    ->where('sport', $artifact->sport)
                    ->where('status', 'promoted')
                    ->where('id', '!=', $artifact->id)
                    ->get()
                    ->each(fn (ModelArtifact $promoted): bool => $this->retireOverlappingMarkets(
                        $promoted,
                        $newlyPromotedMarkets,
                    ));
            }

            $wasPromoted = $artifact->status === 'promoted';
            $artifact->update([
                'status' => $promotedMarkets !== []
                    ? 'promoted'
                    : ($decision['eligible'] ? 'promotion_eligible' : 'challenger'),
                'promotion_criteria' => $decision['criteria'],
                'promotion_decision' => $decision,
                'promoted_at' => $promotedMarkets !== []
                    ? ($wasPromoted ? $artifact->promoted_at : now())
                    : null,
            ]);
        });

        $this->info('Model promotion evaluation');
        $this->table(
            ['Gate', 'Status'],
            collect($decision['checks'])
                ->map(fn (bool $passed, string $gate): array => [$gate, $passed ? 'pass' : 'blocked'])
                ->values()
                ->all(),
        );
        $this->line('Artifact: '.$artifact->id);
        $this->line('Training run: '.$artifact->training_run_id);
        $this->line('Config hash: '.$artifact->trainingRun?->config_hash);
        $this->line('Delta convention: '.$decision['delta_convention']['reported']
            .' (normalized to positive = challenger better)');
        $this->line('Eligible markets: '.($eligibleMarkets === [] ? 'none' : implode(', ', $eligibleMarkets)));
        $this->line('Markets with live evidence: '.($marketsWithLiveEvidence === []
            ? 'none'
            : implode(', ', $marketsWithLiveEvidence)));
        $this->line('Promoted markets: '.($promotedMarkets === [] ? 'none' : implode(', ', $promotedMarkets)));
        $offlineEligibleRequestedMarkets = array_values(array_intersect(
            $marketsRequestedForPromotion,
            $eligibleMarkets,
        ));
        $promotionBlockedByLiveEvidence = (bool) $this->option('promote')
            && $offlineEligibleRequestedMarkets !== []
            && $newlyPromotedMarkets === [];
        $this->line('Result: '.($shouldPromote
            ? 'PROMOTED'
            : ($promotionBlockedByLiveEvidence
                ? 'PROMOTION BLOCKED: LIVE SHADOW EVIDENCE'
                : ($decision['eligible'] ? 'ELIGIBLE, NOT PROMOTED' : 'BLOCKED'))));

        return self::SUCCESS;
    }

    private function numericOption(string $name, bool $integer = false): int|float|null
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return $integer ? (int) $value : (float) $value;
    }

    /**
     * @param  list<string>  $markets
     */
    private function retireOverlappingMarkets(ModelArtifact $artifact, array $markets): bool
    {
        $remaining = array_values(array_diff($artifact->promotedMarkets(), $markets));
        if (count($remaining) === count($artifact->promotedMarkets())) {
            return true;
        }

        $decision = (array) $artifact->promotion_decision;
        $decision['promoted_markets'] = $remaining;
        foreach ((array) ($decision['markets'] ?? []) as $market => $marketDecision) {
            $decision['markets'][$market]['promoted'] = in_array($market, $remaining, true);
        }

        return $artifact->update([
            'status' => $remaining === [] ? 'retired' : 'promoted',
            'promotion_decision' => $decision,
            'promoted_at' => $remaining === [] ? null : $artifact->promoted_at,
        ]);
    }
}
