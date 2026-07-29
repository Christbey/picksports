<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\NflSignalObservationMaterializer;
use Illuminate\Console\Command;
use InvalidArgumentException;

class MaterializeSignalObservationsCommand extends Command
{
    protected $signature = 'nfl:materialize-signal-observations
        {--season= : Materialize one NFL season}
        {--from-season= : First NFL season to materialize}
        {--to-season= : Last NFL season to materialize}
        {--snapshot-id=* : Materialize specific prediction feature snapshot IDs}
        {--all-snapshots : Materialize every historical run instead of the latest snapshot per game}
        {--limit=0 : Optional snapshot limit}';

    protected $description = 'Materialize immutable atomic NFL signal observations from prediction snapshots';

    public function handle(NflSignalObservationMaterializer $materializer): int
    {
        try {
            [$fromSeason, $toSeason] = $this->seasonScope();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $snapshotIds = collect((array) $this->option('snapshot-id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();
        $query = PredictionFeatureSnapshot::query()
            ->with('modelRun')
            ->where('sport', 'nfl')
            ->when($snapshotIds->isNotEmpty(), fn ($builder) => $builder->whereIn('id', $snapshotIds))
            ->when(
                $snapshotIds->isEmpty() && ! (bool) $this->option('all-snapshots'),
                fn ($builder) => $builder->whereIn(
                    'id',
                    PredictionFeatureSnapshot::query()
                        ->selectRaw('MAX(id)')
                        ->where('sport', 'nfl')
                        ->groupBy('game_id')
                )
            )
            ->when(
                $fromSeason !== null,
                fn ($builder) => $builder->whereIn(
                    'game_id',
                    Game::query()
                        ->select('id')
                        ->whereBetween('season', [$fromSeason, $toSeason])
                )
            )
            ->orderBy('id');

        $snapshots = 0;
        $created = 0;
        $existing = 0;
        $skipped = 0;
        $limit = max(0, (int) $this->option('limit'));

        foreach ($query->lazyById(200) as $snapshot) {
            if ($limit > 0 && $snapshots + $skipped >= $limit) {
                break;
            }

            try {
                $result = $materializer->materialize($snapshot);
                $snapshots++;
                $created += $result['created'];
                $existing += $result['existing'];
            } catch (InvalidArgumentException $exception) {
                $skipped++;
                $this->warn($exception->getMessage());
            }
        }

        $this->info(
            "Materialized {$created} NFL signal observation(s) from {$snapshots} snapshot(s); "
            ."{$existing} already existed, {$skipped} snapshot(s) skipped."
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
