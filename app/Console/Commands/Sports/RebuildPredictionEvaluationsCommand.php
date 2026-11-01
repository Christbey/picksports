<?php

namespace App\Console\Commands\Sports;

use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\CanonicalTeamGameEvaluator;
use App\Services\Predictions\PredictionEvaluationRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RebuildPredictionEvaluationsCommand extends Command
{
    protected $signature = 'sports:rebuild-prediction-evaluations
        {--forecast-source= : canonical or legacy (defaults per sport to its canonical pipeline ownership)}
        {--sport=* : Sport keys to rebuild (defaults to all)}
        {--season= : Limit the rebuild to one season}
        {--limit=0 : Maximum predictions to rebuild across all sports}';

    protected $description = 'Rebuild historical or canonical prediction evaluations using their source-specific conventions';

    /** @var list<string> */
    private const SPORTS = ['nba', 'nfl', 'mlb', 'cbb', 'wcbb', 'wnba', 'cfb'];

    public function handle(PredictionEvaluationRecorder $recorder, CanonicalTeamGameEvaluator $canonical, SportContextResolver $contexts): int
    {
        $sports = $this->selectedSports();
        if ($sports === null) {
            return self::INVALID;
        }

        $requestedSource = $this->option('forecast-source');
        if ($requestedSource !== null && ! in_array($requestedSource, ['canonical', 'legacy'], true)) {
            $this->error('Forecast source must be canonical or legacy.');

            return self::INVALID;
        }
        $season = $this->option('season');
        $limit = max(0, (int) $this->option('limit'));
        $rebuilt = 0;
        $canonicalRebuilt = 0;
        $canonicalSkipped = 0;

        foreach ($sports as $sport) {
            $context = $contexts->resolve($sport);
            $source = $requestedSource ?? (config('prediction_lifecycle.canonical_pipeline.'.$sport, false) ? 'canonical' : 'legacy');
            if ($source === 'canonical') {
                $gameClass = $context->models['game'];
                $query = $gameClass::query()->with('sportEvent')->whereNotNull('sport_event_id')
                    ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
                    ->when($season !== null && $season !== '', fn (Builder $query) => $query->where('season', (int) $season));
                foreach ($query->lazyById(250) as $game) {
                    if ($canonical->evaluate($game, $sport) === null) {
                        $canonicalSkipped++;

                        continue;
                    }
                    $canonicalRebuilt++;
                    $rebuilt++;
                    if ($limit > 0 && $rebuilt >= $limit) {
                        break 2;
                    }
                }

                continue;
            }
            $modelClass = $context->models['prediction'];
            $query = $modelClass::query()
                ->with('game')
                ->whereNotNull('graded_at')
                ->whereHas('game', function (Builder $query) use ($season): void {
                    $query
                        ->whereNotNull('home_score')
                        ->whereNotNull('away_score');

                    if ($season !== null && $season !== '') {
                        $query->where('season', (int) $season);
                    }
                })
                ->orderBy('id');

            foreach ($query->lazyById(250) as $prediction) {
                $game = $prediction->game;
                if ($game === null) {
                    continue;
                }

                $actualSpread = (float) $game->home_score - (float) $game->away_score;
                $actualTotal = (float) $game->home_score + (float) $game->away_score;

                $recorder->record($prediction, $game, $sport, $actualSpread, $actualTotal);
                $rebuilt++;

                if ($limit > 0 && $rebuilt >= $limit) {
                    break 2;
                }
            }
        }

        $this->info("Rebuilt {$rebuilt} prediction evaluation rows.");
        if ($canonicalRebuilt > 0 || $canonicalSkipped > 0) {
            $this->line("Canonical evaluations: {$canonicalRebuilt}; skipped final games: {$canonicalSkipped}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function selectedSports(): ?array
    {
        $requested = array_values(array_unique(array_map(
            static fn (mixed $sport): string => strtolower(trim((string) $sport)),
            (array) $this->option('sport')
        )));
        $sports = $requested === [] ? self::SPORTS : $requested;
        $unsupported = array_values(array_diff($sports, self::SPORTS));

        if ($unsupported !== []) {
            $this->error('Unsupported sport keys: '.implode(', ', $unsupported));

            return null;
        }

        return $sports;
    }
}
