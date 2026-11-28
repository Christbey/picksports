<?php

namespace App\Console\Commands\Sports;

use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\CanonicalTeamGameEvaluator;
use App\Services\Predictions\LegacyPredictionGradeRecorder;
use Illuminate\Console\Command;

class RebuildPredictionEvaluationsCommand extends Command
{
    protected $signature = 'sports:rebuild-prediction-evaluations
        {--forecast-source=legacy : Explicit legacy or canonical source; defaults to legacy}
        {--sport=* : Exactly one sport is required}
        {--season= : Assert every selected game belongs to this season}
        {--limit=0 : Optional cap on explicitly selected game IDs, not a history scan}
        {--game=* : Explicit game IDs, maximum100}
        {--apply : Apply reviewed bounded repair; default is dry run}';

    protected $description = 'Compatibility alias for bounded audited legacy forecast grade/evaluation repair';

    public function handle(): int
    {
        $sports = array_values(array_unique(array_map(fn ($sport) => strtolower(trim((string) $sport)), (array) $this->option('sport'))));
        $ids = (array) $this->option('game');
        $limit = (string) $this->option('limit');
        $season = $this->option('season');
        $source = $this->option('forecast-source');
        if (! in_array($source, ['legacy', 'canonical'], true) || count($sports) !== 1 || ! in_array($sports[0], LegacyPredictionGradeRecorder::SPORTS, true)
            || $ids === [] || count($ids) > 100 || ! preg_match('/^[0-9]{1,3}$/D', $limit)
            || ((int) $limit > 0 && count($ids) > (int) $limit)
            || array_filter($ids, fn ($id) => ! is_scalar($id) || ! preg_match('/^[1-9][0-9]{0,17}$/D', (string) $id)) !== []
            || ($season !== null && ! preg_match('/^[0-9]{4}$/D', (string) $season))) {
            $this->error('Unbounded rebuilds are disabled. Supply exactly one --sport and one to100 explicit --game IDs; omit --apply for a dry run. --limit never selects history.');

            return self::INVALID;
        }
        if ($source === 'canonical') {
            return $this->repairCanonical($sports[0], array_values(array_unique($ids)), $season);
        }
        if ($season !== null) {
            $gameClass = 'App\\Models\\'.strtoupper($sports[0]).'\\Game';
            if ($gameClass::query()->whereIn('id', $ids)->where('season', (int) $season)->count() !== count(array_unique($ids))) {
                $this->error('Selected games do not all match the asserted season. Nothing was repaired.');

                return self::INVALID;
            }
        }

        return $this->call('sports:repair-legacy-prediction-grades', [
            'sport' => $sports[0], '--game' => $ids, '--apply' => (bool) $this->option('apply'),
        ]);
    }

    /** Existing canonical evidence gates remain authoritative; this selects no history. */
    private function repairCanonical(string $sport, array $ids, mixed $season): int
    {
        $context = app(SportContextResolver::class)->resolve($sport);
        $gameClass = $context->models['game'];
        $games = $gameClass::query()->with('sportEvent')->whereKey($ids)
            ->when($season !== null, fn ($query) => $query->where('season', (int) $season))
            ->orderBy('id')->get();
        if ($games->count() !== count($ids)) {
            $this->error('Selected canonical games do not all exist in the asserted scope. Nothing was repaired.');

            return self::INVALID;
        }
        if (! $this->option('apply')) {
            $this->line('Dry run: canonical game IDs '.implode(', ', $games->modelKeys()).'. No evaluations written.');

            return self::SUCCESS;
        }
        $evaluated = 0;
        $skipped = 0;
        foreach ($games as $game) {
            if (app(CanonicalTeamGameEvaluator::class)->evaluate($game, $sport) === null) {
                $skipped++;
            } else {
                $evaluated++;
            }
        }
        $this->line("Canonical evaluations: {$evaluated}; skipped selected games: {$skipped}.");

        return self::SUCCESS;
    }
}
