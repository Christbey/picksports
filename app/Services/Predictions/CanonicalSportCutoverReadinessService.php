<?php

namespace App\Services\Predictions;

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
    public function report(string $sport, ?int $season = null, ?int $week = null): array
    {
        $sport = strtolower(trim($sport));
        $gameModel = self::GAME_MODELS[$sport] ?? null;

        if ($gameModel === null) {
            throw new \InvalidArgumentException("Canonical cutover readiness does not support {$sport}.");
        }

        $eligibleGames = $gameModel::query()
            ->whereNotNull('sport_event_id')
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'STATUS_FINAL'])
            ->when($season !== null, fn ($query) => $query->where('season', $season))
            ->when($week !== null, fn ($query) => $query->where('week', $week));
        $eligibleEventIds = (clone $eligibleGames)
            ->pluck('sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $safePredictions = $this->predictions->queryForSport(
            $sport,
            array_filter([
                'season' => $season,
                'week' => $week,
            ], fn (mixed $value): bool => $value !== null),
        );
        $safePredictionEventIds = (clone $safePredictions)
            ->pluck('predictions.sport_event_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $published = CanonicalPrediction::query()
            ->where('sport', $sport)
            ->where('phase', 'pregame')
            ->where('publication_state', 'published')
            ->when($season !== null || $week !== null, fn ($query) => $query->whereHas(
                'sportEvent.'.($sport.'Game'),
                fn ($query) => $query
                    ->when($season !== null, fn ($query) => $query->where('season', $season))
                    ->when($week !== null, fn ($query) => $query->where('week', $week)),
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

        $missingPredictionCount = $eligibleEventIds->diff($safePredictionEventIds)->count();
        $missingEvaluationCount = $safeFinalEventIds->diff($evaluatedEventIds)->count();
        $unsafePublishedCount = max(0, $publishedCount - $safeCount);
        $ready = $missingPredictionCount === 0
            && $missingEvaluationCount === 0
            && $unsafePublishedCount === 0
            && $duplicateEventGroups === 0;

        return [
            'sport' => $sport,
            'season' => $season,
            'week' => $week,
            'ready_for_cutover' => $ready,
            'canonical_reader_enabled' => (bool) config("prediction_lifecycle.canonical_reads.{$sport}", false),
            'eligible_event_count' => $eligibleEventIds->count(),
            'safe_published_event_count' => $safePredictionEventIds->count(),
            'missing_safe_prediction_count' => $missingPredictionCount,
            'published_revision_count' => $publishedCount,
            'unsafe_published_revision_count' => $unsafePublishedCount,
            'duplicate_published_event_count' => $duplicateEventGroups,
            'final_event_count' => $finalEventIds->count(),
            'final_event_with_safe_prediction_count' => $safeFinalEventIds->count(),
            'evaluated_final_event_count' => $evaluatedEventIds->count(),
            'missing_evaluation_count' => $missingEvaluationCount,
            'next_action' => $ready
                ? 'Enable the canonical reader in a staged environment and run API contract smoke tests.'
                : 'Backfill missing safe predictions and evaluations, then rerun this report.',
        ];
    }
}
