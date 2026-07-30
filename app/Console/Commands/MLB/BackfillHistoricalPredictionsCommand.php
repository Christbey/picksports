<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\GeneratePrediction;
use App\Actions\MLB\GradePredictions;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\MLB\Game;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\MLB\TrustedHistoricalFeatureBuilder;
use App\Services\MLB\TrustedHistoricalPredictionReconstructor;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class BackfillHistoricalPredictionsCommand extends Command
{
    protected $signature = 'mlb:backfill-historical-predictions
        {--season= : Backfill a single MLB season}
        {--from-date= : Start date in Y-m-d format}
        {--to-date= : End date in Y-m-d format}
        {--season-type=2 : Filter by season type (defaults to regular season)}
        {--limit=0 : Limit the number of games processed}
        {--only-missing : Only generate predictions for games without an existing prediction}
        {--only-missing-profile : Only generate games without a snapshot for the selected profile}
        {--profile=research-default : Reconstruction profile: research-default or trusted-core-v1}
        {--with-narratives : Dispatch prediction narratives while backfilling}';

    protected $description = 'Generate and grade historical MLB prediction snapshots for completed games';

    public function handle(
        GeneratePrediction $generatePrediction,
        GradePredictions $gradePredictions,
        TrustedHistoricalPredictionReconstructor $trustedReconstructor,
    ): int {
        $profile = (string) $this->option('profile');
        if (! in_array($profile, ['research-default', TrustedHistoricalFeatureBuilder::PROFILE], true)) {
            $this->error("Unknown MLB historical reconstruction profile: {$profile}.");

            return self::FAILURE;
        }

        try {
            [$startDate, $endDate] = $this->resolveDateRange();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $games = $this->gamesQuery($startDate, $endDate, $profile)->get();

        if ($games->isEmpty()) {
            $this->warn('No completed MLB games matched the selected historical scope.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Backfilling MLB historical predictions for %d game(s) from %s to %s using %s.',
            $games->count(),
            $startDate?->toDateString() ?? $games->first()->game_date?->toDateString() ?? 'unknown',
            $endDate?->toDateString() ?? $games->last()->game_date?->toDateString() ?? 'unknown',
            $profile,
        ));

        $withNarratives = (bool) $this->option('with-narratives');
        $generated = 0;
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        foreach ($games as $game) {
            $prediction = $profile === TrustedHistoricalFeatureBuilder::PROFILE
                ? $trustedReconstructor->reconstruct($game)
                : $generatePrediction->executeHistorical($game, $withNarratives);

            if ($prediction !== null) {
                $generated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $grading = $profile === TrustedHistoricalFeatureBuilder::PROFILE
            ? $this->trustedGradingSummary($games->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())
            : $gradePredictions->executeForGameIds(
                $games->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()
            );

        app(SportsViewCache::class)->bustSegments([
            SportsViewCache::SEGMENT_DASHBOARD,
            SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
            SportsViewCache::SEGMENT_PREDICTIONS_BY_GAME,
            SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_DATES,
            SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_SEASONS,
        ]);

        $this->info("Generated or refreshed {$generated} historical MLB prediction row(s).");
        $this->line(sprintf(
            'Graded %d prediction(s); winner accuracy %s%%, spread MAE %s, total MAE %s.',
            (int) ($grading['graded'] ?? 0),
            number_format((float) ($grading['winner_accuracy'] ?? 0), 1),
            number_format((float) ($grading['avg_spread_error'] ?? 0), 2),
            number_format((float) ($grading['avg_total_error'] ?? 0), 2),
        ));

        return self::SUCCESS;
    }

    private function gamesQuery(?Carbon $startDate, ?Carbon $endDate, string $profile): Builder
    {
        $seasonType = $this->option('season-type');
        $limit = max(0, (int) ($this->option('limit') ?? 0));

        $query = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereIn('status', GameData::finalStatuses())
            ->whereNotNull('home_team_id')
            ->whereNotNull('away_team_id')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->orderBy('id');

        if (($season = $this->option('season')) !== null) {
            $query->where('season', (int) $season);
        }

        if ($seasonType !== null && $seasonType !== '') {
            $query->where('season_type', (string) $seasonType);
        }

        if ($startDate !== null) {
            $query->whereDate('game_date', '>=', $startDate->toDateString());
        }

        if ($endDate !== null) {
            $query->whereDate('game_date', '<=', $endDate->toDateString());
        }

        if ((bool) $this->option('only-missing')) {
            $query->whereDoesntHave('prediction');
        }

        if ((bool) $this->option('only-missing-profile')) {
            $gameIdsWithProfile = PredictionFeatureSnapshot::query()
                ->where('sport', 'mlb')
                ->where('prediction_table', 'mlb_predictions')
                ->where('lineage_metadata->historical_profile', $profile)
                ->pluck('game_id')
                ->map(fn (mixed $gameId): int => (int) $gameId)
                ->all();

            if ($gameIdsWithProfile !== []) {
                $query->whereNotIn('id', $gameIdsWithProfile);
            }
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * @param  list<int>  $gameIds
     * @return array{graded:int,winner_accuracy:float,avg_spread_error:float,avg_total_error:float}
     */
    private function trustedGradingSummary(array $gameIds): array
    {
        $snapshotAnchors = PredictionFeatureSnapshot::query()
            ->where('sport', 'mlb')
            ->where('prediction_table', 'mlb_predictions')
            ->whereIn('game_id', $gameIds)
            ->where('model_version', TrustedHistoricalPredictionReconstructor::MODEL_VERSION)
            ->where('feature_version', TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
            ->where('blend_version', TrustedHistoricalPredictionReconstructor::BLEND_VERSION)
            ->where('lineage_metadata->historical_profile', TrustedHistoricalFeatureBuilder::PROFILE)
            ->get(['prediction_id', 'game_id'])
            ->mapWithKeys(fn (PredictionFeatureSnapshot $snapshot): array => [
                $snapshot->prediction_id.':'.$snapshot->game_id => true,
            ]);
        $evaluations = PredictionEvaluation::query()
            ->where('sport', 'mlb')
            ->where('prediction_table', 'mlb_predictions')
            ->whereIn('game_id', $gameIds)
            ->where('model_version', TrustedHistoricalPredictionReconstructor::MODEL_VERSION)
            ->where('feature_version', TrustedHistoricalFeatureBuilder::FEATURE_VERSION)
            ->where('blend_version', TrustedHistoricalPredictionReconstructor::BLEND_VERSION)
            ->get()
            ->filter(fn (PredictionEvaluation $evaluation): bool => $snapshotAnchors->has(
                $evaluation->prediction_id.':'.$evaluation->game_id
            ));
        $errors = $evaluations->map(
            fn (PredictionEvaluation $evaluation): array => (array) $evaluation->errors
        );
        $winnerDecisions = $errors->filter(
            fn (array $error): bool => array_key_exists('winner_correct', $error)
                && $error['winner_correct'] !== null
        );

        return [
            'graded' => $evaluations->count(),
            'winner_accuracy' => $winnerDecisions->isEmpty()
                ? 0.0
                : round($winnerDecisions->where('winner_correct', true)->count() / $winnerDecisions->count() * 100, 1),
            'avg_spread_error' => round((float) ($errors->pluck('spread_error')->filter(
                fn (mixed $value): bool => is_numeric($value)
            )->avg() ?? 0.0), 2),
            'avg_total_error' => round((float) ($errors->pluck('total_error')->filter(
                fn (mixed $value): bool => is_numeric($value)
            )->avg() ?? 0.0), 2),
        ];
    }

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function resolveDateRange(): array
    {
        $season = $this->option('season');
        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');

        if ($season !== null && ($fromDate !== null || $toDate !== null)) {
            throw new \InvalidArgumentException('Use either --season or --from-date/--to-date, not both.');
        }

        if ($season !== null) {
            $year = (int) $season;

            return [
                Carbon::create($year, 2, 1)->startOfDay(),
                Carbon::create($year, 10, 31)->endOfDay(),
            ];
        }

        if ($fromDate === null && $toDate === null) {
            return [null, null];
        }

        $start = Carbon::parse((string) ($fromDate ?? $toDate))->startOfDay();
        $end = Carbon::parse((string) ($toDate ?? $fromDate))->endOfDay();

        if ($start->gt($end)) {
            throw new \InvalidArgumentException('The start date must be on or before the end date.');
        }

        return [$start, $end];
    }
}
