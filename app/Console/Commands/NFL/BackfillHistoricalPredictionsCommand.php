<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Actions\NFL\GradePredictions;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class BackfillHistoricalPredictionsCommand extends Command
{
    protected $signature = 'nfl:backfill-historical-predictions
        {--season= : Backfill a single NFL season}
        {--from-season= : Backfill starting with this NFL season}
        {--to-season= : Backfill through this NFL season}
        {--from-date= : Start date in Y-m-d format}
        {--to-date= : End date in Y-m-d format}
        {--season-type=2 : Filter by season type, default regular season}
        {--limit=0 : Limit the number of games processed}
        {--only-missing : Only generate predictions for games without an existing prediction}
        {--profile=elo-only : Historical model profile: elo-only, rolling-efficiency, qb-form, full-historical, or configured}
        {--regrade : Clear existing grades for selected games before grading}';

    protected $description = 'Generate and grade historical NFL predictions for completed games without requiring betting odds';

    public function handle(
        GeneratePredictionFromHistoricalElo $generatePrediction,
        GradePredictions $gradePredictions
    ): int {
        try {
            [$startDate, $endDate] = $this->resolveDateRange();
            $this->applyHistoricalProfile();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $games = $this->gamesQuery($startDate, $endDate)->get();

        if ($games->isEmpty()) {
            $this->warn('No completed NFL games matched the selected historical scope.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Backfilling NFL historical predictions for %d game(s) from %s to %s using %s profile.',
            $games->count(),
            $startDate?->toDateString() ?? $games->first()->game_date?->toDateString() ?? 'unknown',
            $endDate?->toDateString() ?? $games->last()->game_date?->toDateString() ?? 'unknown',
            (string) $this->option('profile')
        ));

        $generated = 0;
        $updated = 0;
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        foreach ($games as $game) {
            $result = $generatePrediction->execute($game);
            if ($result === 'created') {
                $generated++;
            } elseif ($result === 'updated') {
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $gameIds = $games->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if ((bool) $this->option('regrade')) {
            $this->clearExistingGrades($gameIds);
        }

        $grading = $gradePredictions->executeForGameIds($gameIds);

        app(SportsViewCache::class)->bustSegments([
            SportsViewCache::SEGMENT_DASHBOARD,
            SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
            SportsViewCache::SEGMENT_PREDICTIONS_BY_GAME,
            SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_DATES,
            SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_SEASONS,
        ]);

        $this->info("Generated {$generated} and updated {$updated} historical NFL prediction row(s).");
        $this->line(sprintf(
            'Graded %d prediction(s); winner accuracy %s%%, spread MAE %s, total MAE %s.',
            (int) ($grading['graded'] ?? 0),
            number_format((float) ($grading['winner_accuracy'] ?? 0), 1),
            number_format((float) ($grading['avg_spread_error'] ?? 0), 2),
            number_format((float) ($grading['avg_total_error'] ?? 0), 2),
        ));

        return self::SUCCESS;
    }

    private function applyHistoricalProfile(): void
    {
        $profile = (string) $this->option('profile');

        if (! in_array($profile, ['elo-only', 'rolling-efficiency', 'qb-form', 'line-matchup', 'contextual', 'full-historical', 'configured'], true)) {
            throw new \InvalidArgumentException('The --profile option must be elo-only, rolling-efficiency, qb-form, line-matchup, contextual, full-historical, or configured.');
        }

        if ($profile === 'configured') {
            return;
        }

        $rollingEfficiencyEnabled = in_array($profile, ['rolling-efficiency', 'full-historical'], true);
        $qbFormEnabled = in_array($profile, ['qb-form', 'full-historical'], true);
        $lineMatchupEnabled = in_array($profile, ['line-matchup', 'full-historical'], true);
        $contextualFactorsEnabled = $profile === 'contextual';

        config([
            'nfl.predictions.true_epa.enabled' => false,
            'nfl.predictions.preseason_signal.enabled' => false,
            'nfl.predictions.market_blend.enabled' => false,
            'nfl.predictions.depth_chart_injuries.enabled' => false,
            'nfl.predictions.rolling_efficiency.enabled' => $rollingEfficiencyEnabled,
            'nfl.predictions.qb_form.enabled' => $qbFormEnabled,
            'nfl.predictions.line_matchup.enabled' => $lineMatchupEnabled,
            'nfl.predictions.contextual_factors.enabled' => $contextualFactorsEnabled,
        ]);
    }

    private function gamesQuery(?Carbon $startDate, ?Carbon $endDate): Builder
    {
        $seasonType = $this->option('season-type');
        $limit = max(0, (int) ($this->option('limit') ?? 0));

        $query = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('status', 'STATUS_FINAL')
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

        if (($fromSeason = $this->option('from-season')) !== null) {
            $query->where('season', '>=', (int) $fromSeason);
        }

        if (($toSeason = $this->option('to-season')) !== null) {
            $query->where('season', '<=', (int) $toSeason);
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

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function resolveDateRange(): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');
        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');

        if ($season !== null && ($fromDate !== null || $toDate !== null || $fromSeason !== null || $toSeason !== null)) {
            throw new \InvalidArgumentException('Use --season, --from-season/--to-season, or --from-date/--to-date, not a combination.');
        }

        if (($fromSeason !== null || $toSeason !== null) && ($fromDate !== null || $toDate !== null)) {
            throw new \InvalidArgumentException('Use either --from-season/--to-season or --from-date/--to-date, not both.');
        }

        if ($season !== null) {
            $year = (int) $season;

            return [
                Carbon::create($year, 8, 1)->startOfDay(),
                Carbon::create($year + 1, 2, 28)->endOfDay(),
            ];
        }

        if ($fromSeason !== null || $toSeason !== null) {
            $startYear = (int) ($fromSeason ?? $toSeason);
            $endYear = (int) ($toSeason ?? $fromSeason);

            if ($startYear > $endYear) {
                throw new \InvalidArgumentException('--from-season must be less than or equal to --to-season.');
            }

            return [
                Carbon::create($startYear, 8, 1)->startOfDay(),
                Carbon::create($endYear + 1, 2, 28)->endOfDay(),
            ];
        }

        if ($fromDate === null && $toDate === null) {
            return [null, null];
        }

        return [
            $fromDate ? Carbon::parse($fromDate)->startOfDay() : null,
            $toDate ? Carbon::parse($toDate)->endOfDay() : null,
        ];
    }

    /**
     * @param  list<int>  $gameIds
     */
    private function clearExistingGrades(array $gameIds): void
    {
        if ($gameIds === []) {
            return;
        }

        Prediction::query()
            ->whereIn('game_id', $gameIds)
            ->update([
                'actual_spread' => null,
                'actual_total' => null,
                'spread_error' => null,
                'total_error' => null,
                'winner_correct' => null,
                'graded_at' => null,
            ]);
    }
}
