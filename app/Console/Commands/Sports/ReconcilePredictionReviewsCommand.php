<?php

namespace App\Console\Commands\Sports;

use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Research\GameResearchService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Console\Command;

class ReconcilePredictionReviewsCommand extends Command
{
    protected $signature = 'sports:reconcile-prediction-reviews
        {--sport=* : Sports; omitted selects all enabled sports}
        {--season= : Optional season filter}
        {--date= : First Central business date}
        {--days-forward= : Days after first date}
        {--limit=1000 : Maximum games inspected per sport}
        {--dry-run : Report eligible games without writes}';

    protected $description = 'Reconcile selected forecasts into durable review work without invoking AI';

    public function handle(SportContextResolver $sports, PredictionReviewCoordinator $coordinator, SportsDateWindowService $dates): int
    {
        $requested = array_map('strtolower', (array) $this->option('sport'));
        if (array_diff($requested, $sports->all()->pluck('slug')->all()) !== []) {
            $this->error('Unsupported sport.');

            return self::FAILURE;
        }
        $start = $dates->parseLocalDate($this->option('date'));
        $days = max(0, (int) ($this->option('days-forward') ?? config('prediction_review.days_forward', 7)));
        $window = $dates->forRange($start, $start->addDays($days));
        // Match canonical event storage, independently of legacy UTC game times.
        $eventBounds = [
            $window->localStart->setTimezone((string) config('app.timezone', 'UTC'))->toDateTimeString(),
            $window->localEnd->setTimezone((string) config('app.timezone', 'UTC'))->toDateTimeString(),
        ];
        foreach ($sports->all() as $sport) {
            if (($requested !== [] && ! in_array($sport->slug, $requested, true)) || ! $coordinator->enabled($sport->slug)) {
                continue;
            }
            $model = $sport->models['game'];
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
                ->orderBy('game_date')->orderBy('game_time')->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();
            $eligible = $reconciled = 0;
            foreach ($games as $game) {
                if (! ($game->sportEvent?->starts_at ?? $dates->gameDateTimeUtc($game->game_date, $game->game_time))?->isFuture()) {
                    continue;
                }
                $eligible++;
                if (! $this->option('dry-run')) {
                    $evidence = class_exists(GameResearchService::class) ? app(GameResearchService::class)->forGame($game, $sport->slug) : [];
                    $reconciled += (int) ($coordinator->reconcileGame($game, $sport->slug, $evidence) !== null);
                }
            }
            $this->line(strtoupper($sport->slug).": inspected {$games->count()}; eligible {$eligible}; reconciled {$reconciled}".($this->option('dry-run') ? ' (dry run)' : ''));
        }

        return self::SUCCESS;
    }
}
