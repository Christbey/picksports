<?php

namespace App\Console\Commands\Sports;

use App\Jobs\Predictions\RefreshGameAnalysis;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Research\GameResearchService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Dispatches bounded work; never performs provider calls in the scheduler. */
class ReconcileGameAnalysisCommand extends Command
{
    protected $signature = 'sports:reconcile-game-analysis
        {--sport=* : Sports to reconcile; defaults to every supported sport}
        {--season= : Optional sport season, never inferred from calendar year}
        {--days-forward= : Business-date horizon}
        {--limit= : Maximum dispatches per sport}
        {--dry-run : Inspect coverage without writing or dispatching}';

    protected $description = 'Reconcile future game research and reviews with bounded fair queue dispatch';

    public function handle(SportContextResolver $sports, GameResearchService $research, PredictionReviewCoordinator $reviews, SportsDateWindowService $dates): int
    {
        $requested = array_values(array_unique(array_map('strtolower', (array) $this->option('sport'))));
        $selected = $requested === [] ? $sports->all() : collect($requested)->map(fn ($sport) => $sports->find($sport));
        if ($selected->contains(null)) {
            $this->error('An unsupported sport was requested.');

            return self::FAILURE;
        }
        $limit = max(1, (int) ($this->option('limit') ?? config('game_analysis.batch_per_sport', 4)));
        $window = $dates->forwardWindow(null, max(0, (int) ($this->option('days-forward') ?? config('game_analysis.days_forward', 7))));
        // Canonical event timestamps are stored in the application timezone;
        // legacy game date/time uses the separate UTC storage convention.
        $eventBounds = [
            $window->localStart->setTimezone((string) config('app.timezone', 'UTC'))->toDateTimeString(),
            $window->localEnd->setTimezone((string) config('app.timezone', 'UTC'))->toDateTimeString(),
        ];
        foreach ($selected as $context) {
            $sport = $context->slug;
            if (! $research->enabled($sport) && ! $reviews->enabled($sport)) {
                $this->line(strtoupper($sport).': disabled; no work dispatched.');

                continue;
            }
            $model = $context->models['game'];
            $games = $model::query()->with('sportEvent')->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
                ->when($this->option('season'), fn ($query, $season) => $query->where('season', (int) $season))
                ->where(function ($query) use ($dates, $window, $eventBounds): void {
                    $query->whereHas('sportEvent', fn ($event) => $event->whereBetween('starts_at', $eventBounds))
                        ->orWhere(function ($legacy) use ($dates, $window): void {
                            $legacy->where(fn ($missing) => $missing->whereDoesntHave('sportEvent')
                                ->orWhereHas('sportEvent', fn ($event) => $event->whereNull('starts_at')));
                            $dates->applyGameDateWindow($legacy, $window);
                        });
                })
                ->orderBy('game_date')->orderBy('game_time')->get()
                ->filter(fn ($game) => ($game->sportEvent?->starts_at ?? $dates->gameDateTimeUtc($game->game_date, $game->game_time))?->isFuture());
            $key = fn ($game) => 'game-analysis-dispatch:'.$sport.':'.$game->id;
            $activity = Cache::many($games->map($key)->all());
            $ordered = $games->sortBy(fn ($game) => sprintf('%020d|%s|%020d',
                (int) ($activity[$key($game)] ?? 0), $game->game_date?->toDateString().' '.$game->game_time, $game->id));
            $batch = $ordered->take($limit);
            $this->line(sprintf('%s: %d eligible; %d selected; %d remain.', strtoupper($sport), $games->count(), $batch->count(), max(0, $games->count() - $batch->count())));
            foreach ($batch as $game) {
                if (! $this->option('dry-run')) {
                    RefreshGameAnalysis::dispatch($sport, (int) $game->id);
                    Cache::put($key($game), now()->timestamp, now()->addMinutes(max(1, (int) config('game_analysis.dispatch_rotation_minutes', 10080))));
                }
                $this->line('  '.$sport.' game '.$game->id);
            }
        }

        return self::SUCCESS;
    }
}
