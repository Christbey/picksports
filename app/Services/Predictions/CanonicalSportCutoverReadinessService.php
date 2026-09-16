<?php

namespace App\Services\Predictions;

use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Game as CbbGame;
use App\Models\CFB\Game as CfbGame;
use App\Models\MLB\Game as MlbGame;
use App\Models\NBA\Game as NbaGame;
use App\Models\NFL\Game as NflGame;
use App\Models\PredictionEvaluation;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WNBA\Game as WnbaGame;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class CanonicalSportCutoverReadinessService
{
    /** @var array<string, class-string<Model>> */
    private const GAME_MODELS = [
        'cbb' => CbbGame::class,
        'cfb' => CfbGame::class,
        'mlb' => MlbGame::class,
        'nba' => NbaGame::class,
        'nfl' => NflGame::class,
        'wcbb' => WcbbGame::class,
        'wnba' => WnbaGame::class,
    ];

    public function __construct(private readonly CanonicalSportPredictionQuery $predictions) {}

    /** @return array<string, mixed> */
    /** @param list<string>|null $seasonTypes */
    public function report(
        string $sport,
        ?int $season = null,
        ?int $week = null,
        ?array $seasonTypes = null,
        ?string $releaseVersion = null,
        ?CarbonImmutable $horizonStart = null,
        ?CarbonImmutable $horizonEnd = null,
    ): array {
        $sport = strtolower(trim($sport));
        $gameModel = self::GAME_MODELS[$sport] ?? null;

        if ($gameModel === null) {
            throw new \InvalidArgumentException("Canonical cutover readiness does not support {$sport}.");
        }

        $cutoverStartedAtValue = CalculationRelease::query()
            ->where('sport', $sport)
            ->where('phase', 'pregame')
            ->whereIn('status', ['approved', 'retired'])
            ->whereNotNull('effective_at')
            ->when($releaseVersion !== null, fn ($query) => $query->where('semantic_version', $releaseVersion))
            ->min('effective_at');
        $cutoverStartedAt = $cutoverStartedAtValue === null
            ? null
            : CarbonImmutable::parse($cutoverStartedAtValue);
        $scopedGames = $gameModel::query()
            ->whereNotNull('sport_event_id')
            ->whereIn('status', [
                'STATUS_SCHEDULED',
                'STATUS_DELAYED',
                'STATUS_IN_PROGRESS',
                'STATUS_END_PERIOD',
                'STATUS_HALFTIME',
                'STATUS_FINAL',
            ])
            ->when($season !== null, fn ($query) => $query->where('season', $season))
            ->when($week !== null, fn ($query) => $query->where('week', $week))
            ->when($seasonTypes !== null, fn ($query) => $query->whereIn('season_type', $seasonTypes));
        $scopedEventIds = (clone $scopedGames)
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $historicalEligibleGames = (clone $scopedGames)
            ->when($cutoverStartedAt !== null, fn ($query) => $query->whereHas(
                'sportEvent',
                fn ($query) => $query->where('starts_at', '>=', $cutoverStartedAt),
            ));
        $historicalEligibleEventIds = (clone $historicalEligibleGames)
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $eligibleGames = (clone $historicalEligibleGames)
            ->when($horizonStart !== null, fn ($query) => $query->whereHas(
                'sportEvent',
                fn ($query) => $query->where('starts_at', '>=', $horizonStart),
            ))
            ->when($horizonEnd !== null, fn ($query) => $query->whereHas(
                'sportEvent',
                fn ($query) => $query->where('starts_at', '<=', $horizonEnd),
            ));
        $eligibleEventIds = (clone $eligibleGames)
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $historicalSafePredictions = $this->predictions->queryForSport(
            $sport,
            array_filter([
                'season' => $season,
                'week' => $week,
            ], fn (mixed $value): bool => $value !== null),
        )->when(
            $cutoverStartedAt !== null,
            fn ($query) => $query->where('sport_events.starts_at', '>=', $cutoverStartedAt),
        )->when(
            $seasonTypes !== null,
            fn ($query) => $query->whereIn("{$sport}_games.season_type", $seasonTypes),
        );
        $historicalSafePredictionEventIds = (clone $historicalSafePredictions)
            ->pluck('predictions.sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $safePredictions = (clone $historicalSafePredictions)
            ->when($horizonStart !== null, fn ($query) => $query->where('sport_events.starts_at', '>=', $horizonStart))
            ->when($horizonEnd !== null, fn ($query) => $query->where('sport_events.starts_at', '<=', $horizonEnd));
        $safePredictionEventIds = (clone $safePredictions)
            ->pluck('predictions.sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $historicalPublished = CanonicalPrediction::query()
            ->where('sport', $sport)
            ->where('phase', 'pregame')
            ->where('publication_state', 'published')
            ->when($cutoverStartedAt !== null, fn ($query) => $query->whereHas(
                'sportEvent',
                fn ($query) => $query->where('starts_at', '>=', $cutoverStartedAt),
            ))
            ->when($season !== null || $week !== null || $seasonTypes !== null, fn ($query) => $query->whereHas(
                'sportEvent.'.($sport.'Game'),
                fn ($query) => $query
                    ->when($season !== null, fn ($query) => $query->where('season', $season))
                    ->when($week !== null, fn ($query) => $query->where('week', $week))
                    ->when($seasonTypes !== null, fn ($query) => $query->whereIn('season_type', $seasonTypes)),
            ));
        $historicalPublishedCount = (clone $historicalPublished)->count();
        $historicalSafeCount = (clone $historicalSafePredictions)->count();
        $historicalDuplicateEventGroups = (clone $historicalPublished)
            ->select('sport_event_id')
            ->groupBy('sport_event_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $published = (clone $historicalPublished)
            ->when($horizonStart !== null, fn ($query) => $query->whereHas(
                'sportEvent',
                fn ($query) => $query->where('starts_at', '>=', $horizonStart),
            ))
            ->when($horizonEnd !== null, fn ($query) => $query->whereHas(
                'sportEvent',
                fn ($query) => $query->where('starts_at', '<=', $horizonEnd),
            ));
        $publishedCount = (clone $published)->count();
        $safeCount = (clone $safePredictions)->count();
        $duplicateEventGroups = (clone $published)
            ->select('sport_event_id')
            ->groupBy('sport_event_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $finalEventIds = (clone $eligibleGames)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $safeFinalEventIds = $finalEventIds->intersect($safePredictionEventIds)->values();
        $evaluatedEventIds = PredictionEvaluation::query()
            ->whereNotNull('canonical_prediction_id')
            ->where('sport', $sport)
            ->where('prediction_phase', 'pregame')
            ->whereIn('sport_event_id', $safeFinalEventIds)
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $historicalFinalEventIds = (clone $historicalEligibleGames)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $historicalSafeFinalEventIds = $historicalFinalEventIds
            ->intersect($historicalSafePredictionEventIds)
            ->values();
        $historicalEvaluatedEventIds = PredictionEvaluation::query()
            ->whereNotNull('canonical_prediction_id')
            ->where('sport', $sport)
            ->where('prediction_phase', 'pregame')
            ->whereIn('sport_event_id', $historicalSafeFinalEventIds)
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $missingPredictionCount = $eligibleEventIds->diff($safePredictionEventIds)->count();
        $missingEvaluationCount = $safeFinalEventIds->diff($evaluatedEventIds)->count();
        $unsafePublishedCount = max(0, $publishedCount - $safeCount);
        $ready = $cutoverStartedAt !== null
            && $missingPredictionCount === 0
            && $missingEvaluationCount === 0
            && $unsafePublishedCount === 0
            && $duplicateEventGroups === 0;

        return [
            'sport' => $sport,
            'season' => $season,
            'week' => $week,
            'cutover_release_version' => $releaseVersion,
            'cutover_started_at' => $cutoverStartedAt?->toIso8601String(),
            'horizon_start_at' => $horizonStart?->toIso8601String(),
            'horizon_end_at' => $horizonEnd?->toIso8601String(),
            'ready_for_cutover' => $ready,
            'canonical_reader_enabled' => (bool) config("prediction_lifecycle.canonical_reads.{$sport}", false),
            'eligible_event_count' => $eligibleEventIds->count(),
            'pre_cutover_event_count' => $scopedEventIds->diff($historicalEligibleEventIds)->count(),
            'out_of_horizon_eligible_event_count' => $historicalEligibleEventIds->diff($eligibleEventIds)->count(),
            'safe_published_event_count' => $safePredictionEventIds->count(),
            'missing_safe_prediction_count' => $missingPredictionCount,
            'published_revision_count' => $publishedCount,
            'unsafe_published_revision_count' => $unsafePublishedCount,
            'duplicate_published_event_count' => $duplicateEventGroups,
            'final_event_count' => $finalEventIds->count(),
            'final_event_with_safe_prediction_count' => $safeFinalEventIds->count(),
            'evaluated_final_event_count' => $evaluatedEventIds->count(),
            'missing_evaluation_count' => $missingEvaluationCount,
            'historical_eligible_event_count' => $historicalEligibleEventIds->count(),
            'historical_safe_published_event_count' => $historicalSafePredictionEventIds->count(),
            'historical_missing_safe_prediction_count' => $historicalEligibleEventIds
                ->diff($historicalSafePredictionEventIds)
                ->count(),
            'historical_published_revision_count' => $historicalPublishedCount,
            'historical_unsafe_published_revision_count' => max(0, $historicalPublishedCount - $historicalSafeCount),
            'historical_duplicate_published_event_count' => $historicalDuplicateEventGroups,
            'historical_final_event_count' => $historicalFinalEventIds->count(),
            'historical_missing_evaluation_count' => $historicalSafeFinalEventIds
                ->diff($historicalEvaluatedEventIds)
                ->count(),
            'next_action' => $ready
                ? 'Enable the canonical reader in a staged environment and run API contract smoke tests.'
                : 'Backfill missing safe predictions and evaluations, then rerun this report.',
        ];
    }
}
