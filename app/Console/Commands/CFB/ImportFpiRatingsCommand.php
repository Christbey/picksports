<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\CollegeFootballData\Fpi\FpiSeasonCoordinator;
use App\Services\CollegeFootballData\Fpi\FpiSourceInventory;
use App\Support\CfbSeasonAffiliationResolver;
use App\Support\Sports\ExplicitOffsetInstant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ImportFpiRatingsCommand extends Command
{
    protected $signature = 'cfb:import-fpi
        {--season= : Season to import (defaults to current year)}
        {--week=0 : Snapshot week to store for this import}
        {--require-data : Fail if no usable ratings are imported}
        {--require-complete : Require usable ratings for every FBS team and every provider row to match}
        {--recalculate-metrics : Recalculate CFB team metrics after import}';

    protected $description = 'Import CFB FPI ratings from CollegeFootballData';

    public function handle(CollegeFootballDataService $service, CfbSeasonAffiliationResolver $seasonAffiliationResolver,
        FpiSeasonCoordinator $coordinator): int
    {
        $season = $this->option('season') === null ? (int) date('Y') : filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $week = filter_var($this->option('week'), FILTER_VALIDATE_INT);
        if ($season === false || $week === false) {
            $this->error('FPI season and week must be explicit integers.');

            return self::FAILURE;
        }
        $context = null;
        try {
            $context = $coordinator->begin($season, $week);
            $this->info("Fetching CFB FPI ratings for {$season}...");
            $snapshot = $coordinator->acquireSource($context, fn () => $service->getFpiSeasonSnapshot($season));
            $coordinator->remaining($context);
            $rows = $snapshot['rows'] ?? null;
            $observedAt = ExplicitOffsetInstant::parse($snapshot['observed_at'] ?? null);
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1024 || $observedAt === null || $observedAt->isFuture()) {
                throw new RuntimeException('FPI response shape, source clock or row count is invalid.');
            }
            $observedAt = $observedAt->timezone(config('app.timezone'));
            $this->info('CFBD FPI observed at '.$observedAt->toIso8601String());
            if ($rows === []) {
                $result = $coordinator->commit($context, [], $observedAt, fn () => throw new RuntimeException('Empty source must not enter the rating writer.'));
                if ($result !== self::SUCCESS) {
                    $this->error('Empty FPI acquisition was superseded; no new ratings or source origin published.');

                    return self::FAILURE;
                }
                $this->warn("No FPI ratings were returned for {$season}.");

                return ($this->option('require-data') || $this->option('require-complete')) ? self::FAILURE : self::SUCCESS;
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! is_string($row['team'] ?? null) || strlen($row['team']) > 256
                    || (isset($row['season']) && filter_var($row['season'], FILTER_VALIDATE_INT) !== $season) || (isset($row['year']) && filter_var($row['year'], FILTER_VALIDATE_INT) !== $season)) {
                    throw new RuntimeException('FPI provider row identity is invalid.');
                }
            }
            $result = $coordinator->commit($context, $rows, $observedAt, function ($teams, $mappings, $ratings, Connection $connection) use ($rows, $season, $week, $seasonAffiliationResolver, $observedAt) {
                $bindings = [];
                $result = $this->applyRows($rows, $season, $week, $seasonAffiliationResolver, $observedAt, $teams, $mappings, $ratings, $connection, $bindings);

                return ['result' => $result, 'bindings' => $bindings];
            });
            if ($result !== self::SUCCESS) {
                $this->error('FPI acquisition was superseded; this request published no new ratings or derived work.');

                return self::FAILURE;
            }
            if ($this->option('recalculate-metrics')) {
                // A superseded acquisition must not trigger derived work.
                if ($context->sequence !== (int) (new FpiRating)->getConnection()->table('cfbd_fpi_season_heads')->useWritePdo()->where('season', $season)->value('latest_applied_sequence')) {
                    return self::SUCCESS;
                }

                return $this->call('cfb:calculate-team-metrics', ['--season' => $season]);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($exception instanceof LockTimeoutException) {
                $this->error('FPI source fetch is busy; this attempt published no new ratings. Retry explicitly after the current fetch completes.');
            }
            if ($context !== null) {
                try {
                    $coordinator->finish($context, 'failed', $exception instanceof LockTimeoutException ? 'source_cache_busy' : 'acquisition_or_projection_refused');
                } catch (Throwable $receiptError) {
                    Log::warning('FPI failure receipt unavailable.', ['attempt_id' => $context->attemptId, 'exception_class' => $receiptError::class]);
                }
            }
            $this->error('FPI batch refused: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function applyRows(array $rows, int $season, int $week, CfbSeasonAffiliationResolver $seasonAffiliationResolver, CarbonImmutable $observedAt, Collection $teams, Collection $mappings, Collection $ratings, Connection $connection, array &$intendedBindings): int
    {
        $fbsTeams = $teams->filter(fn (Team $team): bool => $seasonAffiliationResolver->isFbs($team, $season))->values();
        $teamByCfbdId = $fbsTeams
            ->filter(fn (Team $team): bool => $team->cfbd_team_id !== null)
            ->keyBy(fn (Team $team): int => (int) $team->cfbd_team_id);
        $teamByName = $this->buildTeamNameIndex($fbsTeams);
        [$cfbdIdByName, $mappingRowsByCfbdId] = $this->buildCfbdMappingIndexes($mappings);

        $created = 0;
        $updated = 0;
        $matched = 0;
        $usable = 0;
        $usableTeamIds = [];
        $skipped = 0;
        $writes = [];
        $bindings = [];
        $plannedRatings = [];

        foreach ($rows as $row) {
            $team = $this->resolveTeamForRow($row, $teamByName, $teamByCfbdId, $cfbdIdByName, $mappingRowsByCfbdId, $teams);

            if (! $team) {
                $skipped++;

                continue;
            }

            $matched++;

            $logicalExisting = isset($plannedRatings[$team->id]);
            $rating = $plannedRatings[$team->id] ?? $ratings->firstWhere('team_id', $team->id) ?? (new FpiRating)->setConnection($connection->getName())->fill([
                'team_id' => $team->id, 'season' => $season, 'week' => $week,
            ]);
            if ($team->isDirty('cfbd_team_id')) {
                $bindings[$team->id] = $team;
            }

            if ($rating->exists && $rating->updated_at?->gt($observedAt)) {
                $plannedRatings[$team->id] = $rating;
                if (is_numeric($rating->fpi)) {
                    $usable++;
                    $usableTeamIds[] = (int) $team->id;
                }

                continue;
            }

            if ($rating->exists || $logicalExisting) {
                $updated++;
            } else {
                $created++;
            }

            $rating->fill([
                'fpi' => $this->floatOrNull($row['fpi'] ?? null),
                'fpi_rank' => $this->intOrNull(data_get($row, 'resumeRanks.fpi')),
                'offense' => $this->floatOrNull(data_get($row, 'efficiencies.offense')),
                'defense' => $this->floatOrNull(data_get($row, 'efficiencies.defense')),
                'special_teams' => $this->floatOrNull(data_get($row, 'efficiencies.specialTeams')),
            ]);
            if (! $rating->exists || $rating->isDirty()) {
                $rating->created_at ??= now();
                $rating->updated_at = $observedAt;
                $writes[$team->id] = $rating;
            }
            $plannedRatings[$team->id] = $rating;
            if (is_numeric($rating->fpi)) {
                $usable++;
                $usableTeamIds[] = (int) $team->id;
            }
        }

        $this->info("Matched {$matched} rows. Proposed creates {$created}, updates {$updated}, skipped {$skipped}.");

        if ($this->option('require-data') && $usable === 0) {
            $this->error('No usable FPI ratings imported.');

            return self::FAILURE;
        }

        $missing = $fbsTeams->reject(fn (Team $team): bool => in_array((int) $team->id, $usableTeamIds, true));
        $this->info(sprintf('FPI coverage: %d/%d FBS teams; %d provider rows unmatched.',
            count(array_unique($usableTeamIds)), $fbsTeams->count(), $skipped));
        if ($this->option('require-complete') && ($fbsTeams->isEmpty() || $missing->isNotEmpty() || $skipped > 0 || $usable !== count($rows) || count(array_unique($usableTeamIds)) !== $usable)) {
            $this->error('Incomplete FPI coverage; generation must remain on hold. Missing: '.
                ($missing->pluck('school')->implode(', ') ?: 'unmatched or unusable provider rows'));

            return self::FAILURE;
        }

        $bindingPlans = [];
        foreach ($bindings as $team) {
            $id = $this->positiveId($team->getRawOriginal('id'));
            $this->assertStore($team, $connection, 'cfb_teams', $id);
            $bindingPlans[$id] = $this->positiveId($team->getAttributes()['cfbd_team_id'] ?? null);
        }
        // Detach ALL intended scalar values and existing identities before the first persistence observer.
        $intendedBindings = $bindingPlans;
        $writePlans = [];
        $retainedPlans = [];
        foreach ($plannedRatings as $teamId => $rating) {
            $existingId = $rating->exists ? $this->positiveId($rating->getRawOriginal('id')) : null;
            $this->assertStore($rating, $connection, 'cfb_fpi_ratings', $existingId);
            $fields = ['team_id', 'season', 'week', 'fpi', 'fpi_rank', 'offense', 'defense', 'special_teams', 'created_at', 'updated_at'];
            if (! isset($writes[$teamId])) {
                if ($existingId === null) {
                    throw new RuntimeException('FPI retained rating lacks its original stored identity.');
                }
                $retainedPlans[$existingId] = array_intersect_key($rating->getRawOriginal(), array_flip($fields));

                continue;
            }
            $writePlans[] = ['model' => $rating, 'id' => $existingId,
                'values' => array_intersect_key($rating->getAttributes(), array_flip($fields))];
        }
        foreach ($bindings as $team) {
            $id = $this->positiveId($team->getRawOriginal('id'));
            $this->assertStore($team, $connection, 'cfb_teams', $id);
            if ($this->positiveId($team->getAttributes()['cfbd_team_id'] ?? null) !== ($bindingPlans[$id] ?? null)) {
                throw new RuntimeException('FPI intended binding changed before persistence.');
            }
            if ($team->saveQuietly() !== true) {
                throw new RuntimeException('FPI inferred team binding persistence was cancelled.');
            }
            $this->assertStore($team, $connection, 'cfb_teams', $id);
        }
        $ratingPlans = $retainedPlans;
        foreach ($writePlans as $plan) {
            $rating = $plan['model'];
            $existingId = $plan['id'];
            $expected = $plan['values'];
            $this->assertStore($rating, $connection, 'cfb_fpi_ratings', $existingId);
            if (array_intersect_key($rating->getAttributes(), $expected) !== $expected) {
                throw new RuntimeException('FPI intended rating changed before persistence.');
            }
            if ($rating->save(['timestamps' => false]) !== true) {
                throw new RuntimeException('FPI rating persistence was cancelled.');
            }
            $id = $existingId ?? $this->positiveId($rating->getAttributes()['id'] ?? null);
            $this->assertStore($rating, $connection, 'cfb_fpi_ratings', $id);
            if (array_intersect_key($rating->getAttributes(), $expected) !== $expected) {
                throw new RuntimeException('FPI planned values changed during persistence.');
            }
            $ratingPlans[$id] = $expected;
        }
        // Verify ALL planned leaves after the last observer, using literal stores and pinned IDs.
        ksort($bindingPlans, SORT_NUMERIC);
        foreach ($bindingPlans as $id => $expected) {
            $stored = $connection->table('cfb_teams')->useWritePdo()->where('id', $id)->lockForUpdate()->first(['id', 'cfbd_team_id']);
            if ($stored === null || (string) $stored->cfbd_team_id !== (string) $expected) {
                throw new RuntimeException('FPI inferred team binding changed before batch completion.');
            }
        }
        ksort($ratingPlans, SORT_NUMERIC);
        if ($ratingPlans !== []) {
            $storedRows = FpiSourceInventory::ratingProjection($connection->table('cfb_fpi_ratings')->useWritePdo())
                ->whereIntegerInRaw('id', array_keys($ratingPlans))->orderBy('id')->lockForUpdate()->get();
            if ($storedRows->count() !== count($ratingPlans)) {
                throw new RuntimeException('FPI planned rating disappeared before completion.');
            }
            foreach ($storedRows as $stored) {
                if ((int) $stored->invalid_rating_body !== 0) {
                    throw new RuntimeException('FPI stored target scalar exceeded its body budget.');
                }
                $id = $this->positiveId($stored->id);
                $this->assertValues((array) $stored, $ratingPlans[$id] ?? throw new RuntimeException('FPI target identity changed.'), isset($retainedPlans[$id]));
            }
        }

        return self::SUCCESS;
    }

    private function positiveId(mixed $raw): int
    {
        if ((! is_int($raw) && ! is_string($raw)) || ! preg_match('/\A[1-9][0-9]*\z/', (string) $raw)
            || ($id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
            throw new RuntimeException('FPI target primary key is not a canonical positive integer.');
        }

        return $id;
    }

    private function assertStore(Model $model, Connection $connection, string $table, ?int $id): void
    {
        if ($model->getConnection() !== $connection || $model->getTable() !== $table || $model->getKeyName() !== 'id'
            || ($id === null && ($model->exists || array_key_exists('id', $model->getAttributes())))
            || ($id !== null && (! $model->exists || $this->positiveId($model->getAttributes()['id'] ?? null) !== $id))) {
            throw new RuntimeException('FPI planned literal store, owner or primary key changed.');
        }
    }

    private function assertValues(array $stored, array $expected, bool $retained = false): void
    {
        foreach ($expected as $field => $value) {
            $found = $stored[$field] ?? null;
            $same = $retained ? $found === $value : ($value === null ? $found === null : (in_array($field, ['created_at', 'updated_at'], true)
                ? $found === $value : (is_numeric($found) && is_finite((float) $found)
                    && (in_array($field, ['fpi', 'offense', 'defense', 'special_teams'], true)
                        ? round((float) $found, 3) === (float) $value : (string) $found === (string) $value))));
            if (! $same) {
                throw new RuntimeException('FPI stored values do not preserve the requested source precision.');
            }
        }
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @return array<string, Team>
     */
    protected function buildTeamNameIndex(Collection $teams): array
    {
        $index = [];

        foreach ($teams as $team) {
            foreach ([
                $team->school,
                $team->location,
                $team->display_name,
                $team->short_display_name,
                $team->abbreviation,
                $team->name,
            ] as $candidate) {
                $normalized = $this->normalizeName($candidate);

                if ($normalized !== '' && ! isset($index[$normalized])) {
                    $index[$normalized] = $team;
                }
            }
        }

        return $index;
    }

    /**
     * @return array<string, int>
     */
    protected function buildCfbdMappingIndexes(Collection $mappings): array
    {
        $index = [];
        $rowsByCfbdId = [];

        foreach ($mappings as $mapping) {
            $rowsByCfbdId[(int) $mapping->cfbd_team_id] = $mapping;

            foreach (array_filter([
                $mapping->cfbd_team_name,
                $mapping->espn_team_name,
                $mapping->cfbd_abbreviation,
                ...((array) $mapping->alternate_names),
            ]) as $candidate) {
                $normalized = $this->normalizeName($candidate);

                if ($normalized !== '') {
                    $index[$normalized] = (int) $mapping->cfbd_team_id;
                }
            }
        }

        return [$index, $rowsByCfbdId];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Team>  $teamByName
     * @param  Collection<int, Team>  $teamByCfbdId
     * @param  array<string, int>  $cfbdIdByName
     * @param  array<int, CfbdTeamMapping>  $mappingRowsByCfbdId
     */
    protected function resolveTeamForRow(
        array $row,
        array $teamByName,
        Collection $teamByCfbdId,
        array $cfbdIdByName,
        array $mappingRowsByCfbdId,
        Collection $allTeams,
    ): ?Team {
        $rowName = $this->normalizeName($row['team'] ?? null);

        if ($rowName === '') {
            return null;
        }

        $direct = $teamByName[$rowName] ?? null;
        if ($direct instanceof Team) {
            return $direct;
        }

        $cfbdTeamId = $cfbdIdByName[$rowName] ?? null;
        if ($cfbdTeamId === null) {
            return null;
        }

        $team = $teamByCfbdId->get($cfbdTeamId);
        if ($team instanceof Team) {
            return $team;
        }

        $mapping = $mappingRowsByCfbdId[$cfbdTeamId] ?? null;
        if ($mapping instanceof CfbdTeamMapping) {
            foreach (array_filter([
                $mapping->espn_team_name,
                ...((array) $mapping->alternate_names),
            ]) as $candidate) {
                $mappedTeam = $teamByName[$this->normalizeName($candidate)] ?? null;

                if ($mappedTeam instanceof Team) {
                    if ((int) ($mappedTeam->cfbd_team_id ?? 0) !== $cfbdTeamId) {
                        $mappedTeam->forceFill(['cfbd_team_id' => $cfbdTeamId]);
                    }

                    return $mappedTeam;
                }
            }
        }

        return $allTeams->firstWhere('cfbd_team_id', $cfbdTeamId);
    }

    protected function normalizeName(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) $value));
        $normalized = str_replace(['’', '\''], '', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    protected function floatOrNull(mixed $value): ?float
    {
        if (is_numeric($value) && ! is_finite((float) $value)) {
            throw new RuntimeException('FPI numeric source must be finite.');
        }

        return is_numeric($value) ? round((float) $value, 3) : null;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
