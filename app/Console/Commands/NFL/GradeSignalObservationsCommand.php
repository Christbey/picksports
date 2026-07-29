<?php

namespace App\Console\Commands\NFL;

use App\Models\NflSignalObservation;
use App\Services\NFL\NflSignalGradingService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class GradeSignalObservationsCommand extends Command
{
    protected $signature = 'nfl:grade-signal-observations
        {--season= : Grade one NFL season}
        {--from-season= : First NFL season to grade}
        {--to-season= : Last NFL season to grade}
        {--signal-type= : Restrict grading to one signal type}
        {--signal-key= : Restrict grading to one signal key}
        {--limit=0 : Optional observation limit}';

    protected $description = 'Grade NFL signal observations on finalized games and linked bet settlements';

    public function handle(NflSignalGradingService $gradingService): int
    {
        try {
            [$fromSeason, $toSeason] = $this->seasonScope();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $query = NflSignalObservation::query()
            ->with('featureSnapshot')
            ->when(
                $fromSeason !== null,
                fn ($builder) => $builder->whereBetween('season', [$fromSeason, $toSeason])
            )
            ->when(
                $this->option('signal-type'),
                fn ($builder) => $builder->where('signal_type', (string) $this->option('signal-type'))
            )
            ->when(
                $this->option('signal-key'),
                fn ($builder) => $builder->where('signal_key', (string) $this->option('signal-key'))
            )
            ->orderBy('id');

        $graded = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $limit = max(0, (int) $this->option('limit'));

        foreach ($query->lazyById(500) as $observation) {
            if ($limit > 0 && $graded + $skipped >= $limit) {
                break;
            }

            $result = $gradingService->grade($observation);
            if ($result['skipped']) {
                $skipped++;

                continue;
            }

            $graded++;
            $created += $result['created'];
            $updated += $result['updated'];
        }

        $this->info(
            "Graded {$graded} NFL signal observation(s); {$created} grade row(s) created, "
            ."{$updated} refreshed, {$skipped} awaiting a finalized game."
        );

        return self::SUCCESS;
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function seasonScope(): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($season !== null && ($fromSeason !== null || $toSeason !== null)) {
            throw new InvalidArgumentException('Use either --season or --from-season/--to-season, not both.');
        }

        if ($season !== null) {
            return [(int) $season, (int) $season];
        }

        if ($fromSeason === null && $toSeason === null) {
            return [null, null];
        }

        $from = (int) ($fromSeason ?? $toSeason);
        $to = (int) ($toSeason ?? $fromSeason);
        if ($from > $to) {
            throw new InvalidArgumentException('--from-season must be less than or equal to --to-season.');
        }

        return [$from, $to];
    }
}
