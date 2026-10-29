<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCanonicalPredictionsCommand;
use App\Models\NFL\Game;
use App\Services\NFL\Predictions\NflCanonicalCutoverReadinessService;
use App\Services\NFL\Predictions\NflPregameHorizon;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCanonicalPredictionsCommand
{
    protected $signature = 'nfl:generate-canonical-predictions {--game=} {--season=} {--date=} {--days-forward=8} {--from-date=} {--to-date=} {--draft} {--verify-readiness}';

    protected $description = 'Generate canonical NFL predictions';

    protected function gameClass(): string
    {
        return Game::class;
    }

    protected function generatorClass(): string
    {
        return GenerateCanonicalPrediction::class;
    }

    protected function sportLabel(): string
    {
        return 'NFL';
    }

    /** @return list<string> */
    protected function allowedSeasonTypes(): array
    {
        return NflPregameHorizon::seasonTypes();
    }

    public function handle(): int
    {
        $generationStatus = parent::handle();

        if (! $this->option('verify-readiness')) {
            return $generationStatus;
        }

        $season = filled($this->option('season')) ? (int) $this->option('season') : null;
        $report = app(NflCanonicalCutoverReadinessService::class)->report(
            $season,
            filled($this->option('date')) ? (string) $this->option('date') : null,
            (int) $this->option('days-forward'),
        );
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        if (! $report['ready_for_cutover']) {
            $this->error('NFL canonical readiness verification failed after generation.');

            return self::FAILURE;
        }

        return $generationStatus;
    }

    /** @return array{start: mixed, end: mixed} */
    protected function generationHorizon(): array
    {
        if (filled($this->option('from-date')) || filled($this->option('to-date'))) {
            $dates = app(SportsDateWindowService::class);
            $start = $this->option('from-date') ?: now($dates->timezone())->toDateString();
            $end = $this->option('to-date') ?: CarbonImmutable::parse($start, $dates->timezone())->addDays((int) $this->option('days-forward'))->toDateString();
            $window = $dates->forRange($start, $end);

            return ['start' => $window->utcStart, 'end' => $window->utcEnd];
        }

        return app(NflPregameHorizon::class)->resolve(
            filled($this->option('date')) ? (string) $this->option('date') : null,
            (int) $this->option('days-forward'),
        );
    }
}
