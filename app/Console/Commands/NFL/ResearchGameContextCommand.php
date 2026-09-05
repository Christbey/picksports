<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\SportsGameContextReport;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ResearchGameContextCommand extends Command
{
    protected $signature = 'nfl:research-game-context
        {--date= : Slate date, defaults to today}
        {--days-forward=0 : Include games through N days after the slate date}
        {--season= : Optional season filter}
        {--limit=16 : Maximum games to research}
        {--force : Research again even when fresh context exists}
        {--provider= : Override AI provider}
        {--model= : Override AI model}
        {--retry-rate-limit=1 : Number of times to retry a rate-limited AI request before stopping}
        {--retry-rate-limit-delay=30 : Seconds to wait between rate-limit retries}';

    protected $description = 'Research current, sourced web context for NFL games before prediction analysis';

    public function handle(
        NflWebContextResearchService $research,
        SportsDateWindowService $dateWindowService,
    ): int {
        if (! Schema::hasTable('sports_game_context_reports')) {
            $this->error('Missing sports_game_context_reports table. Run php artisan migrate first.');

            return self::FAILURE;
        }

        if (! config('ai.features.nfl_game_context_research.enabled', true)) {
            $this->warn('NFL game-context web research is disabled.');

            return self::SUCCESS;
        }

        $start = $dateWindowService->parseLocalDate($this->option('date') ? (string) $this->option('date') : null);
        $end = $start->addDays(max(0, (int) $this->option('days-forward')));
        $window = $dateWindowService->forRange($start, $end);
        $season = $this->option('season') ?: config('nfl.season.default');
        $limit = max(1, (int) $this->option('limit'));
        $provider = $this->option('provider') ? (string) $this->option('provider') : null;
        $model = $this->option('model') ? (string) $this->option('model') : null;
        $rateLimitRetries = max(0, (int) $this->option('retry-rate-limit'));
        $rateLimitRetryDelay = max(1, (int) $this->option('retry-rate-limit-delay'));

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where(fn ($query) => $dateWindowService->applyGameDateWindow($query, $window))
            ->where('status', (string) config('nfl.statuses.scheduled', 'STATUS_SCHEDULED'))
            ->when($season, fn ($query) => $query->where('season', (int) $season))
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->limit($limit)
            ->get();

        $this->line('NFL: '.$games->count().' game(s) queued for sourced context research.');
        $saved = 0;
        $skipped = 0;
        $failed = 0;
        $rateLimited = false;
        $estimatedCost = 0.0;

        foreach ($games as $game) {
            if ($rateLimited) {
                break;
            }

            $matchup = (string) ($game->short_name ?: $game->name ?: 'game '.$game->id);

            if ($this->kickoffHasPassed($game)) {
                $this->line('  - skipped '.$matchup.' because its synced market kickoff has passed');
                $skipped++;

                continue;
            }

            if (! $this->option('force') && $this->hasFreshContext((int) $game->id)) {
                $this->line('  - fresh context already exists for '.$matchup);
                $skipped++;

                continue;
            }

            $result = null;
            $failure = null;

            for ($attempt = 0; $attempt <= $rateLimitRetries; $attempt++) {
                try {
                    $result = $research->research($game, $provider, $model);
                    $failure = null;

                    break;
                } catch (Throwable $exception) {
                    $failure = $exception;

                    if (! $this->isRateLimitFailure($exception)
                        || $this->isNonRetryableQuotaFailure($exception)
                        || $attempt >= $rateLimitRetries) {
                        break;
                    }

                    $this->warn(sprintf(
                        '  - rate limited while researching %s; retrying in %d second(s) (%d/%d).',
                        $matchup,
                        $rateLimitRetryDelay,
                        $attempt + 1,
                        $rateLimitRetries,
                    ));

                    sleep($rateLimitRetryDelay);
                }
            }

            if ($result !== null) {
                /** @var SportsGameContextReport $report */
                $report = $result['report'];
                $generationCost = is_numeric($result['generation']?->cost_usd ?? null)
                    ? (float) $result['generation']->cost_usd
                    : null;
                if ($generationCost !== null) {
                    $estimatedCost += $generationCost;
                }
                $this->line(sprintf(
                    '  - saved %s -> %s (%d confidence, %d sources%s)',
                    $matchup,
                    $report->status,
                    $report->confidence,
                    count((array) $report->sources),
                    $generationCost === null ? '' : sprintf(', est. $%.4f', $generationCost),
                ));
                $saved++;

                continue;
            }

            if ($failure !== null) {
                report($failure);
                $this->warn('  - failed '.$matchup.': '.$failure->getMessage());
                $failed++;

                if ($this->isRateLimitFailure($failure)) {
                    $rateLimited = true;
                    $reason = $this->isNonRetryableQuotaFailure($failure)
                        ? 'the provider account has no available quota'
                        : 'the provider is rate limited';
                    $this->warn('  - stopping remaining NFL context research for this run because '.$reason.'.');
                }
            }
        }

        $this->info("NFL game-context research complete. Saved {$saved}; skipped {$skipped}; failed {$failed}.");
        if ($estimatedCost > 0) {
            $this->line(sprintf('Estimated OpenAI cost for completed research: $%.4f.', $estimatedCost));
        }

        return $failed > 0 && $saved === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function hasFreshContext(int $gameId): bool
    {
        return SportsGameContextReport::query()
            ->where('sport', 'nfl')
            ->where('game_id', $gameId)
            ->whereIn('status', ['ready', 'partial'])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function kickoffHasPassed(Game $game): bool
    {
        $commenceTime = data_get($game->odds_data, 'commence_time');
        if (! is_string($commenceTime) || trim($commenceTime) === '') {
            return false;
        }

        try {
            return Carbon::parse($commenceTime)->isPast();
        } catch (Throwable) {
            return false;
        }
    }

    private function isRateLimitFailure(Throwable $exception): bool
    {
        $normalized = strtolower($exception->getMessage());

        return str_contains($normalized, 'rate limited')
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'too many requests')
            || str_contains($normalized, 'code 429')
            || str_contains($normalized, 'status 429');
    }

    private function isNonRetryableQuotaFailure(Throwable $exception): bool
    {
        $normalized = strtolower($exception->getMessage());

        return str_contains($normalized, 'insufficient_quota')
            || str_contains($normalized, 'credit_balance_exhausted')
            || str_contains($normalized, 'no credits remaining')
            || str_contains($normalized, 'billing_hard_limit_reached');
    }
}
