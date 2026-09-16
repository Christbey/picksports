<?php

namespace App\Services\NFL\Predictions;

use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflReleasedBetDecisionRecorder;
use App\Services\Predictions\CanonicalSportCutoverReadinessService;
use Carbon\CarbonImmutable;
use Throwable;

class NflCanonicalCutoverReadinessService
{
    public function __construct(
        private readonly CanonicalSportCutoverReadinessService $readiness,
        private readonly NflPregameHorizon $horizons,
        private readonly NflReleasedBetDecisionRecorder $decisionRecorder,
    ) {}

    /** @return array<string,mixed> */
    public function report(?int $season = null, ?string $date = null, int $daysForward = 8): array
    {
        $horizon = $this->horizons->resolve($date, $daysForward);
        $report = $this->readiness->report(
            'nfl',
            $season,
            seasonTypes: NflPregameHorizon::seasonTypes(),
            releaseVersion: NflCalculationReleaseDefinition::SEMANTIC_VERSION,
            horizonStart: $horizon['start'],
            horizonEnd: $horizon['end'],
        );
        $configuration = [
            'canonical_pipeline_enabled' => (bool) config(
                'prediction_lifecycle.canonical_pipeline.nfl',
                false,
            ),
            'true_epa_enabled' => (bool) config('nfl.predictions.true_epa.enabled', false),
            'true_epa_backfill_enabled' => (bool) config(
                'nfl.predictions.true_epa.backfill_before_generation',
                false,
            ),
            'research_pipeline_enabled' => (bool) config('nfl_research.enabled', false),
        ];
        $configurationBlockers = collect($configuration)
            ->reject()
            ->keys()
            ->map(fn (string $key): string => match ($key) {
                'canonical_pipeline_enabled' => 'canonical_pipeline_disabled',
                'true_epa_enabled' => 'true_epa_disabled',
                'true_epa_backfill_enabled' => 'true_epa_preflight_disabled',
                'research_pipeline_enabled' => 'research_pipeline_disabled',
            })
            ->values()
            ->all();
        $coverageBlockers = [];
        if ((int) $report['eligible_event_count'] === 0) {
            $coverageBlockers[] = 'no_eligible_regular_season_events';
        }
        if ((int) $report['safe_published_event_count'] === 0) {
            $coverageBlockers[] = 'zero_safe_canonical_predictions';
        }

        $slateCoverage = $this->slateCoverage(
            $season,
            $report['cutover_started_at'],
            $horizon['start'],
            $horizon['end'],
        );
        $slateEventCount = (int) $slateCoverage['slate_event_count'];
        if ($slateEventCount === 0) {
            $coverageBlockers[] = 'no_upcoming_regular_season_slate';
        }
        if ((int) $slateCoverage['true_epa_disposition_event_count'] < $slateEventCount) {
            $coverageBlockers[] = 'missing_true_epa_disposition';
        }
        if ((int) $slateCoverage['true_epa_explicit_hold_event_count'] > 0) {
            $coverageBlockers[] = 'true_epa_holds_present';
        }
        if ((int) $slateCoverage['immutable_pregame_market_event_count'] < $slateEventCount) {
            $coverageBlockers[] = 'missing_immutable_pregame_market';
        }
        if ((int) $slateCoverage['spread_quote_coverage_event_count'] < $slateEventCount) {
            $coverageBlockers[] = 'missing_spread_quote_coverage';
        }
        if ((int) $slateCoverage['total_quote_coverage_event_count'] < $slateEventCount) {
            $coverageBlockers[] = 'missing_total_quote_coverage';
        }
        if ((int) $slateCoverage['released_candidate_decision_count']
            < (int) $slateCoverage['official_candidate_market_count']) {
            $coverageBlockers[] = 'missing_released_candidate_decisions';
        }
        if ($slateCoverage['stale_prediction_game_ids'] !== []) {
            $coverageBlockers[] = 'stale_pregame_predictions';
        }
        if ($slateCoverage['missing_disposition_game_ids'] !== []) {
            $coverageBlockers[] = 'missing_market_dispositions';
        }
        $coverageBlockers = array_values(array_unique($coverageBlockers));
        $blockers = [...$configurationBlockers, ...$coverageBlockers];
        $dataReady = (bool) $report['ready_for_cutover'] && $coverageBlockers === [];
        $ready = $dataReady && $configurationBlockers === [];

        return [
            ...$report,
            ...$configuration,
            ...$slateCoverage,
            'true_epa_authority' => 'legacy_nfl_prediction_recommendation_layer',
            'canonical_true_epa_role' => 'not_applied_to_canonical_forecast',
            'horizon_date' => $horizon['date'],
            'horizon_days_forward' => $horizon['days_forward'],
            'data_ready_for_cutover' => $dataReady,
            'configuration_blockers' => $configurationBlockers,
            'coverage_blockers' => $coverageBlockers,
            'readiness_blockers' => $blockers,
            'ready_for_cutover' => $ready,
            'next_action' => $this->nextAction($blockers, $ready),
        ];
    }

    /** @param list<string> $blockers */
    private function nextAction(array $blockers, bool $ready): string
    {
        if ($ready) {
            return 'Run API contract smoke tests and collect canonical comparison results. Keep canonical reads disabled until a separate model-performance review approves promotion.';
        }

        return match (true) {
            in_array('canonical_pipeline_disabled', $blockers, true) => 'Set PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true, clear cached configuration, and verify the effective value before generation.',
            in_array('true_epa_disabled', $blockers, true) => 'Set NFL_TRUE_EPA_ENABLED=true and verify true-EPA readiness before generation.',
            in_array('true_epa_preflight_disabled', $blockers, true) => 'Set NFL_TRUE_EPA_BACKFILL_BEFORE_GENERATION=true so incomplete slate metrics are refreshed or explicitly held.',
            in_array('research_pipeline_disabled', $blockers, true) => 'Set NFL_RESEARCH_PIPELINE_ENABLED=true and verify bounded research jobs are scheduled.',
            in_array('no_eligible_regular_season_events', $blockers, true) => 'Verify regular-season NFL event identity and register a non-backdated release before the next kickoff.',
            in_array('no_upcoming_regular_season_slate', $blockers, true) => 'Verify the upcoming regular-season slate, canonical event identity, and kickoff timestamps.',
            in_array('zero_safe_canonical_predictions', $blockers, true) => 'Generate safe canonical predictions for every eligible upcoming regular-season event, then rerun readiness.',
            in_array('missing_true_epa_disposition', $blockers, true) => 'Run legacy NFL prediction generation and require true EPA to be applied or explicitly held with missing_true_epa for every slate game.',
            in_array('true_epa_holds_present', $blockers, true) => 'Backfill true EPA for every explicitly held slate game, rerun legacy predictions, and require zero missing_true_epa holds before cutover.',
            in_array('missing_immutable_pregame_market', $blockers, true) => 'Refresh odds and regenerate canonical NFL predictions so every slate game freezes a fresh immutable pregame spread and total.',
            in_array('missing_spread_quote_coverage', $blockers, true) => 'Refresh two-sided spread quotes and regenerate canonical NFL predictions for the incomplete slate games.',
            in_array('missing_total_quote_coverage', $blockers, true) => 'Refresh two-sided total quotes and regenerate canonical NFL predictions for the incomplete slate games.',
            in_array('missing_released_candidate_decisions', $blockers, true) => 'Resolve every official-candidate market to an exact fresh paired quote, rerun legacy generation, and require a released tracking decision for every candidate market.',
            in_array('stale_pregame_predictions', $blockers, true) => 'Run the ordered NFL pregame pipeline to refresh stale legacy and canonical forecasts before publishing recommendations.',
            in_array('missing_market_dispositions', $blockers, true) => 'Regenerate legacy NFL forecasts to persist an immutable released, pass, or hold disposition for all three markets on every slate game.',
            default => 'Backfill missing safe predictions and evaluations, then rerun this report.',
        };
    }

    /** @return array<string, mixed> */
    private function slateCoverage(
        ?int $season,
        mixed $cutoverStartedAt,
        CarbonImmutable $horizonStart,
        CarbonImmutable $horizonEnd,
    ): array {
        $maximumQuoteAgeMinutes = max(
            1,
            (int) config('nfl.predictions.pregame_market.maximum_quote_age_minutes', 60),
        );
        $cutover = is_string($cutoverStartedAt) && $cutoverStartedAt !== ''
            ? CarbonImmutable::parse($cutoverStartedAt)
            : null;
        $games = Game::query()
            ->with('sportEvent')
            ->whereNotNull('sport_event_id')
            ->whereIn('season_type', $this->regularSeasonTypes())
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->when($season !== null, fn ($query) => $query->where('season', $season))
            ->whereHas('sportEvent', fn ($query) => $query
                ->where('starts_at', '>=', $horizonStart)
                ->where('starts_at', '<=', $horizonEnd)
                ->when($cutover !== null, fn ($query) => $query->where('starts_at', '>=', $cutover)))
            ->orderBy('game_date')
            ->orderBy('id')
            ->get();
        $eventIds = $games->pluck('sport_event_id')->map(fn (mixed $id): int => (int) $id)->all();
        $gameIds = $games->modelKeys();
        $canonicalByEvent = CanonicalPrediction::query()
            ->with('calculationRun.inputSnapshot')
            ->where('sport', 'nfl')
            ->where('phase', 'pregame')
            ->where('publication_state', 'published')
            ->whereIn('sport_event_id', $eventIds)
            ->orderByDesc('revision')
            ->get()
            ->unique('sport_event_id')
            ->keyBy('sport_event_id');
        $legacyByGame = Prediction::query()
            ->whereIn('game_id', $gameIds)
            ->get()
            ->keyBy('game_id');

        $epaApplied = [];
        $epaHeld = [];
        $epaMissing = [];
        $immutableMarket = [];
        $spreadCoverage = [];
        $totalCoverage = [];
        $candidateMarketCount = 0;
        $releasedCandidateCount = 0;
        $candidateGameIds = [];
        $missingDecisionGameIds = [];
        $missingCandidateMarkets = [];
        $candidateHoldReasons = [];
        $missingDispositionGameIds = [];
        $stalePredictions = [];
        $maximumPredictionAgeMinutes = max(1, (int) config('nfl.predictions.pregame_market.maximum_prediction_age_minutes', 360));
        $predictionFreshAfter = now()->subMinutes($maximumPredictionAgeMinutes);

        $decisionsByPrediction = BetDecision::query()
            ->with('featureSnapshot')
            ->where('sport', 'nfl')
            ->where('prediction_table', (new Prediction)->getTable())
            ->whereIn('prediction_id', $legacyByGame->pluck('id')->filter()->all())
            ->whereIn('status', ['released_tracking_bet', 'held_candidate', 'model_no_bet', 'model_hold'])
            ->get()
            ->groupBy('prediction_id');
        $latestFeatureSnapshots = PredictionFeatureSnapshot::query()
            ->where('sport', 'nfl')->where('prediction_table', (new Prediction)->getTable())
            ->whereIn('prediction_id', $legacyByGame->pluck('id')->filter()->all())
            ->where('pregame_safe', true)->latest('generated_at')->latest('id')
            ->get(['id', 'prediction_id'])->unique('prediction_id')->keyBy('prediction_id');

        foreach ($games as $game) {
            $legacy = $legacyByGame->get($game->getKey());
            $recordedKeys = $decisionsByPrediction->get($legacy?->getKey(), collect())
                ->filter(function (BetDecision $decision) use ($game, $legacy, $latestFeatureSnapshots): bool {
                    $snapshot = $decision->featureSnapshot;
                    $start = $game->sportEvent?->starts_at;

                    return $snapshot?->pregame_safe === true
                        && $start !== null
                        && $snapshot->generated_at !== null && $snapshot->generated_at->lt($start)
                        && $decision->decided_at !== null && $decision->decided_at->lt($start)
                        && ($decision->status === 'released_tracking_bet'
                            || $snapshot->id === $latestFeatureSnapshots->get($legacy?->id)?->id);
                })->pluck('market_key')->unique()->all();
            if (array_diff(['h2h', 'spreads', 'totals'], $recordedKeys) !== []) {
                $missingDispositionGameIds[] = (int) $game->getKey();
            }
            $metadata = $legacy instanceof Prediction ? (array) $legacy->model_metadata : [];
            if (data_get($metadata, 'true_epa.applied') === true) {
                $epaApplied[] = (int) $game->getKey();
            } elseif ($this->hasExplicitTrueEpaHold($metadata)) {
                $epaHeld[] = (int) $game->getKey();
            } else {
                $epaMissing[] = (int) $game->getKey();
            }

            $canonical = $canonicalByEvent->get($game->sport_event_id);
            $inputSnapshot = $canonical instanceof CanonicalPrediction
                ? $canonical->calculationRun?->inputSnapshot
                : null;
            if ($legacy?->updated_at === null
                || $legacy->updated_at->lt($predictionFreshAfter)
                || $inputSnapshot?->captured_at === null
                || $inputSnapshot->captured_at->lt($predictionFreshAfter)) {
                $stalePredictions[] = (int) $game->getKey();
            }
            $market = (array) data_get($inputSnapshot?->inputs, 'pregame_market', []);
            $marketAsOf = $inputSnapshot?->captured_at?->toImmutable();
            $freshAfter = $marketAsOf?->subMinutes($maximumQuoteAgeMinutes);
            $marketCapturedAt = $this->marketCapturedAt($market);
            $fresh = $freshAfter !== null
                && $marketAsOf !== null
                && $marketCapturedAt !== null
                && $marketCapturedAt->betweenIncluded($freshAfter, $marketAsOf);
            $spreadCovered = $fresh
                && $this->marketCovered($market, 'spread', $freshAfter, $marketAsOf);
            $totalCovered = $fresh
                && $this->marketCovered($market, 'total', $freshAfter, $marketAsOf);

            if ($spreadCovered) {
                $spreadCoverage[] = (int) $game->getKey();
            }
            if ($totalCovered) {
                $totalCoverage[] = (int) $game->getKey();
            }
            if (($market['available'] ?? false) === true
                && ($market['source'] ?? null) === 'market_quotes'
                && filled($market['game_odds_snapshot_id'] ?? null)
                && $spreadCovered
                && $totalCovered) {
                $immutableMarket[] = (int) $game->getKey();
            }

            if (! $legacy instanceof Prediction) {
                continue;
            }

            $candidateKeys = $this->decisionRecorder->candidateMarketKeys($legacy);
            if ($candidateKeys === []) {
                continue;
            }

            $candidateGameIds[] = (int) $game->getKey();
            $candidateMarketCount += count($candidateKeys);
            $predictionDecisions = $decisionsByPrediction->get($legacy->getKey(), collect());
            $releasedKeys = $predictionDecisions
                ->filter(fn (BetDecision $decision): bool => $decision->status === 'released_tracking_bet'
                    && $decision->is_bet
                    && $decision->pregame_safe
                    && $decision->decided_at !== null
                    && $game->sportEvent?->starts_at !== null
                    && $decision->decided_at->lt($game->sportEvent->starts_at))
                ->pluck('market_key')
                ->unique()
                ->all();
            $releasedCandidateCount += count(array_intersect($candidateKeys, $releasedKeys));
            $missingKeys = array_values(array_diff($candidateKeys, $releasedKeys));
            if ($missingKeys === []) {
                continue;
            }

            $missingDecisionGameIds[] = (int) $game->getKey();
            $missingCandidateMarkets[(int) $game->getKey()] = $missingKeys;
            $candidateHoldReasons[(int) $game->getKey()] = $predictionDecisions
                ->whereIn('market_key', $missingKeys)
                ->reject(fn (BetDecision $decision): bool => $decision->status === 'released_tracking_bet')
                ->mapWithKeys(fn (BetDecision $decision): array => [
                    $decision->market_key => data_get($decision->explanation, 'hold_reason'),
                ])
                ->filter()
                ->all();
        }

        return [
            'slate_event_count' => $games->count(),
            'slate_game_ids' => $games->modelKeys(),
            'legacy_prediction_event_count' => $legacyByGame->count(),
            'recorded_disposition_event_count' => count($gameIds) - count($missingDispositionGameIds),
            'missing_disposition_game_ids' => $missingDispositionGameIds,
            'true_epa_applied_event_count' => count($epaApplied),
            'true_epa_explicit_hold_event_count' => count($epaHeld),
            'true_epa_disposition_event_count' => count($epaApplied) + count($epaHeld),
            'missing_true_epa_disposition_game_ids' => $epaMissing,
            'immutable_pregame_market_event_count' => count($immutableMarket),
            'missing_immutable_pregame_market_game_ids' => collect($gameIds)->diff($immutableMarket)->values()->all(),
            'spread_quote_coverage_event_count' => count($spreadCoverage),
            'missing_spread_quote_game_ids' => collect($gameIds)->diff($spreadCoverage)->values()->all(),
            'total_quote_coverage_event_count' => count($totalCoverage),
            'missing_total_quote_game_ids' => collect($gameIds)->diff($totalCoverage)->values()->all(),
            'maximum_quote_age_minutes' => $maximumQuoteAgeMinutes,
            'maximum_prediction_age_minutes' => $maximumPredictionAgeMinutes,
            'stale_prediction_game_ids' => $stalePredictions,
            'official_candidate_game_count' => count(array_unique($candidateGameIds)),
            'official_candidate_market_count' => $candidateMarketCount,
            'released_candidate_decision_count' => $releasedCandidateCount,
            'missing_released_candidate_decision_game_ids' => array_values(array_unique($missingDecisionGameIds)),
            'missing_released_candidate_markets_by_game' => $missingCandidateMarkets,
            'candidate_decision_hold_reasons_by_game' => $candidateHoldReasons,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function hasExplicitTrueEpaHold(array $metadata): bool
    {
        return data_get($metadata, 'true_epa.enabled') === true
            && data_get($metadata, 'true_epa.applied') !== true
            && data_get($metadata, 'analysis_layer.eligibility.status') === 'hold'
            && in_array(
                'missing_true_epa',
                (array) data_get($metadata, 'analysis_layer.eligibility.data_reasons', []),
                true,
            );
    }

    /** @param array<string, mixed> $market */
    private function marketCovered(
        array $market,
        string $marketName,
        CarbonImmutable $freshAfter,
        CarbonImmutable $marketAsOf,
    ): bool {
        $consensus = (array) data_get($market, "consensus.{$marketName}", []);
        $capturedAt = $this->quoteObservedAt($consensus);
        $oppositeCapturedAt = $this->quoteObservedAt(
            (array) data_get($consensus, 'opposite_quote', []),
        );

        return data_get($market, "market_coverage.{$marketName}", true) === true
            && is_numeric($consensus['line'] ?? null)
            && is_numeric($consensus['price'] ?? null)
            && is_numeric(data_get($consensus, 'opposite_quote.line'))
            && is_numeric(data_get($consensus, 'opposite_quote.price'))
            && ($capturedAt?->betweenIncluded($freshAfter, $marketAsOf) ?? false)
            && ($oppositeCapturedAt?->betweenIncluded($freshAfter, $marketAsOf) ?? false);
    }

    /** @param array<string, mixed> $quote */
    private function quoteObservedAt(array $quote): ?CarbonImmutable
    {
        return $this->parseTimestamp(
            $quote['freshness_observed_at']
                ?? $quote['provider_observed_at']
                ?? $quote['captured_at']
                ?? null,
        );
    }

    /** @param array<string, mixed> $market */
    private function marketCapturedAt(array $market): ?CarbonImmutable
    {
        return $this->parseTimestamp($market['captured_at'] ?? null);
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function regularSeasonTypes(): array
    {
        return NflPregameHorizon::seasonTypes();
    }
}
