<?php

namespace App\Console\Commands\Sports;

use App\Models\CanonicalPrediction;
use App\Services\Sports\PipelineCommandRunner;
use App\Services\Sports\SportsDateWindowService;
use App\Support\BasketballSeason;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EnsureBasketballPredictionsCommand extends Command
{
    protected $signature = 'sports:ensure-basketball-predictions
        {sport : nba, cbb, or wcbb}
        {--season= : Ending year of the basketball season}
        {--days-forward=2 : Include games through this many days after today}
        {--limit=200 : Maximum predictions to generate per pass}
        {--refresh-existing : Rebuild NBA team metrics and refresh upcoming forecasts}';

    protected $description = 'Fill missing basketball predictions before tipoff and fail visibly when coverage cannot be repaired';

    public function handle(SportsDateWindowService $dates, PipelineCommandRunner $runner): int
    {
        $sport = strtolower((string) $this->argument('sport'));
        if (! in_array($sport, ['nba', 'cbb', 'wcbb'], true)) {
            $this->error('Sport must be nba, cbb, or wcbb.');

            return self::FAILURE;
        }

        $refresh = (bool) $this->option('refresh-existing');
        if ($refresh && $sport !== 'nba') {
            $this->error('Existing forecast recovery is supported only for NBA.');

            return self::FAILURE;
        }

        $lock = Cache::lock('basketball-prediction-coverage:'.$sport, 600);
        if (! $lock->get()) {
            $this->warn('Prediction coverage is already running.');

            return self::FAILURE;
        }

        try {
            $season = (int) ($this->option('season') ?: BasketballSeason::forDate());
            $namespace = strtoupper($sport);
            $gameClass = "App\\Models\\{$namespace}\\Game";
            $canonical = (bool) config("prediction_lifecycle.canonical_pipeline.{$sport}", false);
            $actionClass = "App\\Actions\\{$namespace}\\".($canonical ? 'GenerateCanonicalPrediction' : 'GeneratePrediction');
            $query = $gameClass::query()->with(['homeTeam', 'awayTeam', 'sportEvent'])
                ->where('season', $season)
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'STATUS_IN_PROGRESS', 'STATUS_FINAL']);
            $dates->applyGameDateWindow($query, $dates->forwardWindow(null, max(0, (int) $this->option('days-forward'))));
            if ($refresh) {
                $query->with('prediction')->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED']);
                foreach ([2, 3] as $seasonType) {
                    if ($runner->call('nba:calculate-team-metrics', ['--season' => $season, '--season-type' => $seasonType, '--no-interaction' => true]) !== self::SUCCESS) {
                        $this->error('NBA metrics recovery failed; forecasts were not refreshed.');

                        return self::FAILURE;
                    }
                }
            } elseif ($canonical) {
                $query->whereDoesntHave('sportEvent.predictions', fn ($query) => $query
                    ->where('phase', 'pregame')->where('publication_state', 'published'));
            } else {
                $query->whereDoesntHave('prediction');
            }
            if (method_exists($gameClass, 'scopeWithoutCompletedPlayoffSeriesPlaceholders')) {
                $query->withoutCompletedPlayoffSeriesPlaceholders();
            }

            $generated = 0;
            $attempted = 0;
            $missing = 0;
            $limit = max(1, (int) $this->option('limit'));
            foreach ($query->lazyById(100) as $game) {
                $tipoff = $game->sportEvent?->starts_at
                    ?? $dates->gameDateTimeUtc($game->getRawOriginal('game_date'), $game->game_time);
                try {
                    if ($tipoff === null || (blank($game->game_time) && $game->sportEvent?->starts_at === null)) {
                        throw new RuntimeException('Tipoff time is unavailable.');
                    }
                    if ($refresh && $tipoff->lte(now())) {
                        continue;
                    }
                    if ($tipoff->lte(now()) || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
                        throw new RuntimeException('Tipoff passed without a pregame prediction; retrospective generation blocked.');
                    }
                    if ($attempted >= $limit) {
                        throw new RuntimeException('Coverage batch limit reached.');
                    }
                    $attempted++;
                    $prediction = $canonical
                        ? app($actionClass)->execute($game, true, 'coverage')
                        : app($actionClass)->execute($game, false);
                    if (! $prediction instanceof Model || ($canonical && (! $prediction instanceof CanonicalPrediction || $prediction->publication_state !== 'published'))) {
                        throw new RuntimeException('Generator did not save a usable pregame prediction.');
                    }
                    $generated++;
                } catch (Throwable $exception) {
                    $missing++;
                    $this->error("Game {$game->id}: {$exception->getMessage()}");
                    Log::error('Basketball pregame prediction coverage missing', [
                        'sport' => $sport, 'season' => $season, 'game_id' => $game->id,
                        'reason' => $exception->getMessage(),
                    ]);
                }
            }

            if ($generated > 0) {
                app(SportsViewCache::class)->bustSegments([
                    SportsViewCache::SEGMENT_DASHBOARD,
                    SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
                    SportsViewCache::SEGMENT_PREDICTIONS_BY_GAME,
                    SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_DATES,
                    SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_SEASONS,
                ]);
            }
            $this->info("Pregame coverage: {$generated} generated; {$missing} still missing.");

            return $missing === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
