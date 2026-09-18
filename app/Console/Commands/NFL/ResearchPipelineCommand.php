<?php

namespace App\Console\Commands\NFL;

use App\Models\AiGeneration;
use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\ResearchSource;
use App\Models\SportsGameContextReport;
use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ResearchPipelineCommand extends Command
{
    protected $signature = 'nfl:research-pipeline
        {--date=}
        {--days-forward=7}
        {--limit=16 : Maximum games, or teams for an ingest-only batch}
        {--grade-limit= : Maximum final ungraded revisions to inspect}
        {--grade-batch-size= : Final revisions loaded per database batch}
        {--grade-retry-after-minutes= : Minimum minutes before a pending revision is retried}
        {--ingest-only}
        {--no-ingest}
        {--no-web}
        {--grade}
        {--briefs}';

    protected $description = 'Ingest official NFL updates, research both sides, preserve revised forecasts and evaluate outcomes';

    public function handle(OfficialSourceIngestor $ingestor, ResearchPipeline $pipeline, SportsDateWindowService $dates): int
    {
        if (! config('nfl_research.enabled')) {
            $this->error('NFL research pipeline disabled');

            return self::FAILURE;
        }
        if ($this->option('grade')) {
            if ($this->option('grade-limit') !== null && (int) $this->option('grade-limit') < 1) {
                $this->error('--grade-limit must be at least 1.');

                return self::FAILURE;
            }
            if ($this->option('grade-batch-size') !== null && (int) $this->option('grade-batch-size') < 1) {
                $this->error('--grade-batch-size must be at least 1.');

                return self::FAILURE;
            }
            if ($this->option('grade-retry-after-minutes') !== null && (int) $this->option('grade-retry-after-minutes') < 0) {
                $this->error('--grade-retry-after-minutes cannot be negative.');

                return self::FAILURE;
            }

            $limit = min(
                max(1, (int) config('nfl_research.grading.hard_max_per_run', 1000)),
                max(1, (int) ($this->option('grade-limit')
                    ?? config('nfl_research.grading.max_per_run', 250))),
            );
            $batchSize = min(
                $limit,
                250,
                max(1, (int) ($this->option('grade-batch-size')
                    ?? config('nfl_research.grading.batch_size', 50))),
            );
            $retryAfterMinutes = max(0, (int) ($this->option('grade-retry-after-minutes')
                ?? config('nfl_research.grading.retry_after_minutes', 55)));
            $inspected = 0;
            $graded = 0;
            $grammar = DB::connection()->getQueryGrammar();
            $marginColumn = $grammar->wrap('nfl_research_revisions.evaluation->actual_margin');
            $totalColumn = $grammar->wrap('nfl_research_revisions.evaluation->actual_total');
            $revisionIds = ResearchRevision::query()
                ->where(function ($query) use ($marginColumn, $totalColumn): void {
                    $query->whereNull('graded_at')
                        ->orWhereHas('game', fn ($game) => $game->where(function ($changed) use ($marginColumn, $totalColumn): void {
                            $changed->whereColumn('nfl_games.result_updated_at', '>', 'nfl_research_revisions.graded_at')
                                ->orWhereRaw("{$marginColumn} <> (nfl_games.home_score - nfl_games.away_score)")
                                ->orWhereRaw("{$totalColumn} <> (nfl_games.home_score + nfl_games.away_score)");
                        }));
                })
                ->where(function ($query) use ($retryAfterMinutes): void {
                    $query->whereNull('grade_attempted_at')
                        ->orWhere('grade_attempted_at', '<=', now()->subMinutes($retryAfterMinutes));
                })
                ->whereHas('game', fn ($game) => $game
                    ->where('status', (string) config('nfl.statuses.final', 'STATUS_FINAL'))
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score'))
                ->orderByRaw('CASE WHEN grade_attempted_at IS NULL THEN 0 ELSE 1 END')
                ->orderBy('grade_attempted_at')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');
            foreach ($revisionIds->chunk($batchSize) as $batchIds) {
                $rows = ResearchRevision::query()
                    ->whereIn('id', $batchIds)
                    ->get()
                    ->keyBy('id');
                foreach ($batchIds as $id) {
                    $row = $rows->get($id);
                    if ($row) {
                        $inspected++;
                        $row->forceFill(['grade_attempted_at' => now()])->save();
                        $graded += (int) $pipeline->grade($row);
                    }
                }
            }
            $this->info(sprintf(
                'Inspected %d final ungraded research revision(s); completed %d, pending %d.',
                $inspected,
                $graded,
                $inspected - $graded,
            ));

            return self::SUCCESS;
        }
        $start = $dates->parseLocalDate($this->option('date'));
        $window = $dates->forRange($start, $start->addDays(max(0, (int) $this->option('days-forward'))));
        $games = Game::with(['homeTeam', 'awayTeam', 'prediction'])->where(fn ($q) => $dates->applyGameDateWindow($q, $window))->orderBy('game_date')->orderBy('game_time')->get();
        if ($this->option('briefs')) {
            foreach ($games as $game) {
                $revision = ResearchRevision::where('game_id', $game->id)->latest('id')->first();
                if ($revision) {
                    if ($reason = data_get($revision->brief, 'research_refresh.deferred_reason')) {
                        $failed = true;
                        $this->warn('Game '.$game->id.' paid research deferred: '.$reason.'. Existing evidence has not been revalidated.');
                    }
                    $this->line(json_encode(['revision_id' => $revision->id, 'baseline' => $revision->baseline, 'revised' => $revision->revised, 'brief' => $revision->brief, 'market' => $revision->market], JSON_UNESCAPED_SLASHES));
                }
            }

            return self::SUCCESS;
        }
        $games = $games->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->filter(fn (Game $game): bool => $dates->gameDateTimeUtc($game->game_date, $game->game_time)?->isFuture() ?? false);
        $teams = $games->flatMap(fn ($g) => [$g->homeTeam?->abbreviation, $g->awayTeam?->abbreviation])->map(fn ($t) => $t === 'WSH' ? 'WAS' : $t)->filter()->unique()->all();
        $failed = false;
        if (! $this->option('no-ingest')) {
            if ($this->option('ingest-only')) {
                $teams = $this->teamsForIngestion($teams, max(1, (int) $this->option('limit')));
                $this->line(sprintf('NFL source coverage: polling %d team(s) in this batch.', count($teams)));
            }
            $result = $ingestor->sync($teams);
            $this->line(json_encode(['sources' => $result]));
            $failed = collect($result)->contains(fn ($r) => isset($r['error']));
        }
        if ($this->option('ingest-only')) {
            return $failed ? self::FAILURE : self::SUCCESS;
        }
        // Shared cache records attempts even when inputs deduplicate to an older
        // immutable revision, preserving round-robin coverage across small batches.
        $activity = $this->reviewActivity($games->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $games = $games->sortBy(fn ($game): string => sprintf(
            '%s|%020d',
            $this->cachedActivity((int) $game->id, $activity[(int) $game->id] ?? '0000'),
            (int) $game->id,
        ))->values();
        $selected = $games->take(max(1, (int) $this->option('limit')));
        $this->line(sprintf(
            'NFL research coverage: %d eligible game(s); reviewing %d this run; %d remain for the next batch.',
            $games->count(),
            $selected->count(),
            max(0, $games->count() - $selected->count()),
        ));
        foreach ($selected as $game) {
            try {
                $revision = $pipeline->review($game, ! $this->option('no-web'));
                if ($revision) {
                    $this->line(json_encode(['game_id' => $game->id, 'revision_id' => $revision->id, 'new_revision' => $revision->wasRecentlyCreated, 'eligibility' => $revision->brief['eligibility'], 'change' => $revision->brief['change']]));
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error('Game '.$game->id.' research failed: '.class_basename($e));
                if (app(AiProviderRateLimitCircuitBreaker::class)->isRateLimitFailure($e->getMessage())
                    || str_contains($e->getMessage(), 'rate-limit cooldown')) {
                    $this->warn('Stopping web research during the provider cooldown; remaining games stay pending.');

                    break;
                }
            } finally {
                try {
                    Cache::put(
                        $this->rotationKey((int) $game->id),
                        now()->toIso8601String(),
                        now()->addMinutes(max(1, (int) config('nfl_research.review_rotation_ttl_minutes', 10080))),
                    );
                } catch (Throwable) {
                    // Persistent reports/revisions remain the rotation fallback.
                }
                gc_collect_cycles();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function rotationKey(int $gameId): string
    {
        return 'nfl-research-last-reviewed:'.$gameId;
    }

    /** @param array<int, int> $gameIds @return array<int, string> */
    private function reviewActivity(array $gameIds): array
    {
        $activity = [];
        $reports = SportsGameContextReport::query()
            ->where('sport', 'nfl')
            ->whereIn('game_id', $gameIds)
            ->selectRaw('game_id, MAX(researched_at) as activity_at')
            ->groupBy('game_id')
            ->pluck('activity_at', 'game_id');
        foreach ($reports as $gameId => $activityAt) {
            $activity[(int) $gameId] = $this->normalizedActivity($activityAt);
        }
        $revisions = ResearchRevision::query()
            ->whereIn('game_id', $gameIds)
            ->selectRaw('game_id, MAX(created_at) as activity_at')
            ->groupBy('game_id')
            ->pluck('activity_at', 'game_id');
        foreach ($revisions as $gameId => $activityAt) {
            $activity[(int) $gameId] = max($activity[(int) $gameId] ?? '0000', $this->normalizedActivity($activityAt));
        }
        $generations = AiGeneration::query()
            ->where('purpose', 'nfl_game_context_research')
            ->whereIn('context_id', array_map('strval', $gameIds))
            ->selectRaw('context_id, MAX(started_at) as activity_at')
            ->groupBy('context_id')
            ->pluck('activity_at', 'context_id');
        foreach ($generations as $gameId => $activityAt) {
            $activity[(int) $gameId] = max($activity[(int) $gameId] ?? '0000', $this->normalizedActivity($activityAt));
        }

        return $activity;
    }

    private function cachedActivity(int $gameId, string $persisted): string
    {
        try {
            return max($persisted, $this->normalizedActivity(Cache::get($this->rotationKey($gameId))));
        } catch (Throwable) {
            return $persisted;
        }
    }

    private function normalizedActivity(mixed $value): string
    {
        if (! $value || $value === '0000') {
            return '0000';
        }

        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s.u');
    }

    /** @param array<int, string> $teams @return array<int, string> */
    private function teamsForIngestion(array $teams, int $limit): array
    {
        $checkedAt = ResearchSource::query()
            ->whereIn('team', $teams)
            ->get(['team', 'kind', 'checked_at'])
            ->groupBy('team')
            ->map(function ($sources): string {
                if ($sources->pluck('kind')->intersect(['rss', 'newsroom', 'roster', 'injury'])->unique()->count() < 4) {
                    return '0000';
                }

                return $sources->min(fn (ResearchSource $source): string => $source->checked_at?->toIso8601String() ?? '0000');
            });

        return collect($teams)
            ->sortBy(fn (string $team): string => ($checkedAt->get($team, '0000')).'|'.$team)
            ->take($limit)
            ->values()
            ->all();
    }
}
