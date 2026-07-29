<?php

namespace App\Console\Commands\Sports;

use App\Models\NBA\Prediction;
use App\Services\Predictions\PredictionEvaluationRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RebuildPredictionEvaluationsCommand extends Command
{
    protected $signature = 'sports:rebuild-prediction-evaluations
        {--sport=* : Sport keys to rebuild (defaults to all)}
        {--season= : Limit the rebuild to one season}
        {--limit=0 : Maximum predictions to rebuild across all sports}';

    protected $description = 'Rebuild evaluation rows from graded predictions using the canonical market conventions';

    /**
     * @var array<string, class-string<Model>>
     */
    private const PREDICTION_MODELS = [
        'nba' => Prediction::class,
        'nfl' => \App\Models\NFL\Prediction::class,
        'mlb' => \App\Models\MLB\Prediction::class,
        'cbb' => \App\Models\CBB\Prediction::class,
        'wcbb' => \App\Models\WCBB\Prediction::class,
        'wnba' => \App\Models\WNBA\Prediction::class,
        'cfb' => \App\Models\CFB\Prediction::class,
    ];

    public function handle(PredictionEvaluationRecorder $recorder): int
    {
        $sports = $this->selectedSports();
        if ($sports === null) {
            return self::INVALID;
        }

        $season = $this->option('season');
        $limit = max(0, (int) $this->option('limit'));
        $rebuilt = 0;

        foreach ($sports as $sport) {
            $modelClass = self::PREDICTION_MODELS[$sport];
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
        $sports = $requested === [] ? array_keys(self::PREDICTION_MODELS) : $requested;
        $unsupported = array_values(array_diff($sports, array_keys(self::PREDICTION_MODELS)));

        if ($unsupported !== []) {
            $this->error('Unsupported sport keys: '.implode(', ', $unsupported));

            return null;
        }

        return $sports;
    }
}
