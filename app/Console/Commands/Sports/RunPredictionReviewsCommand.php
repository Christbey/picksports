<?php

namespace App\Console\Commands\Sports;

use App\Jobs\Predictions\ReviewPredictionWorkItem;
use App\Models\AnalysisWorkItem;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Predictions\Review\PredictionReviewRunner;
use App\Support\SportCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RunPredictionReviewsCommand extends Command
{
    protected $signature = 'sports:run-prediction-reviews
        {--sport=* : Limit to enabled sports}
        {--limit=28 : Maximum jobs per run}
        {--inline : Process inline instead of dispatching bounded queue jobs}
        {--dry-run : Show due work without calling AI or dispatching}';

    protected $description = 'Dispatch fair bounded review batches and report durable coverage separately from completion';

    public function handle(PredictionReviewCoordinator $coordinator, PredictionReviewRunner $runner): int
    {
        if (! config('prediction_review.enabled', false)) {
            $this->line('Shared prediction reviews are disabled; existing work is unchanged.');

            return self::SUCCESS;
        }
        if (! Schema::hasTable('analysis_work_items')) {
            $this->error('Review storage is unavailable; apply additive schema before activation.');

            return self::FAILURE;
        }
        $sports = array_keys(array_filter((array) config('prediction_review.sports', [])));
        $requested = array_map('strtolower', (array) $this->option('sport'));
        if (array_diff($requested, SportCatalog::ALL) !== []) {
            $this->error('Unsupported sport.');

            return self::FAILURE;
        }
        if ($requested !== []) {
            $sports = array_values(array_intersect($sports, $requested));
        }
        if (! $this->option('dry-run')) {
            AnalysisWorkItem::whereIn('sport', $sports)->whereIn('state', ['pending', 'queued', 'deferred', 'running'])
                ->where('deadline_at', '<=', now())->update(['state' => 'cancelled', 'reason_code' => 'pregame_deadline_passed', 'lease_token' => null, 'lease_expires_at' => null]);
        }
        // Rotate sport admission by its most recent attempt, so a small global
        // batch cannot repeatedly spend the entire budget on the first sport.
        $activity = AnalysisWorkItem::whereIn('sport', $sports)
            ->selectRaw("sport, MAX(last_attempt_at) as attempted_at, MAX(last_dispatched_at) as dispatched_at, SUM(CASE WHEN state = 'queued' THEN 1 ELSE 0 END) as queued_count")
            ->groupBy('sport')->get()->keyBy('sport');
        $priority = fn (string $sport): array => [
            max($activity->get($sport)?->attempted_at ?? '', $activity->get($sport)?->dispatched_at ?? ''),
            (int) ($activity->get($sport)?->queued_count ?? 0), $sport,
        ];
        usort($sports, fn (string $left, string $right): int => $priority($left) <=> $priority($right));
        $processed = $failed = 0;
        $maximum = max(1, (int) $this->option('limit'));
        $quota = max(1, (int) config('prediction_review.per_sport_batch', 4));
        foreach ($sports as $sport) {
            if (! $coordinator->enabled($sport) || $processed >= $maximum) {
                continue;
            }
            $items = AnalysisWorkItem::where('sport', $sport)->where(function ($query): void {
                $query->whereIn('state', ['pending', 'deferred'])->orWhere(fn ($running) => $running->whereIn('state', ['running', 'queued'])->where('lease_expires_at', '<=', now()));
            })->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where('deadline_at', '>', now())
                ->orderByRaw('CASE WHEN last_attempt_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_attempt_at')->orderBy('deadline_at')->orderBy('id')
                ->limit(min($quota, $maximum - $processed))->get();
            foreach ($items as $item) {
                $processed++;
                if ($this->option('dry-run')) {
                    $this->line("{$sport}: work {$item->id} due ({$item->state})");
                } elseif ($this->option('inline')) {
                    $result = $runner->run($item);
                    $failed += (int) in_array($result, ['failed', 'deferred'], true);
                    $this->line("{$sport}: work {$item->id} {$result}");
                } else {
                    $claimed = AnalysisWorkItem::whereKey($item->id)->where('state', $item->state)
                        ->where('desired_fingerprint', $item->desired_fingerprint)
                        ->when(in_array($item->state, ['queued', 'running'], true), fn ($query) => $query->where('lease_expires_at', '<=', now()))
                        ->update(['state' => 'queued', 'last_dispatched_at' => now(), 'lease_expires_at' => now()->addSeconds(max(300, (int) config('prediction_review.lease_seconds', 180) * 2))]);
                    if ($claimed === 0) {
                        continue;
                    }
                    ReviewPredictionWorkItem::dispatch($item->id);
                }
            }
        }
        $counts = AnalysisWorkItem::selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state')->all();
        $this->info('Selected '.$processed.'; coverage '.json_encode($counts, JSON_THROW_ON_ERROR).'. Dispatch is not completion.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
