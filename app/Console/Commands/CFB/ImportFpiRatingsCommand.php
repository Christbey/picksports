<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ImportFpiRatingsCommand extends Command
{
    protected $signature = 'cfb:import-fpi
        {--season= : Season to import (defaults to current year)}
        {--week=0 : Snapshot week to store for this import}
        {--require-data : Fail if no usable ratings are imported}
        {--require-complete : Require usable ratings for every FBS team and every provider row to match}
        {--recalculate-metrics : Recalculate CFB team metrics after import}';

    protected $description = 'Import CFB FPI ratings from CollegeFootballData';

    public function handle(
        CollegeFootballDataService $service,
        CfbSeasonAffiliationResolver $seasonAffiliationResolver,
    ): int {
        $season = (int) ($this->option('season') ?: date('Y'));
        $week = max(0, (int) $this->option('week'));

        $this->info("Fetching CFB FPI ratings for {$season}...");

        $rows = $service->getFpiRatings($season);

        if ($rows === []) {
            $this->warn("No FPI ratings were returned for {$season}.");

            return ($this->option('require-data') || $this->option('require-complete')) ? self::FAILURE : self::SUCCESS;
        }

        $teams = Team::query()->get();
        $fbsTeams = $teams->filter(fn (Team $team): bool => $seasonAffiliationResolver->isFbs($team, $season))->values();
        $teamByCfbdId = $fbsTeams
            ->filter(fn (Team $team): bool => $team->cfbd_team_id !== null)
            ->keyBy(fn (Team $team): int => (int) $team->cfbd_team_id);
        $teamByName = $this->buildTeamNameIndex($fbsTeams);
        [$cfbdIdByName, $mappingRowsByCfbdId] = $this->buildCfbdMappingIndexes();

        $created = 0;
        $updated = 0;
        $matched = 0;
        $usable = 0;
        $usableTeamIds = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $team = $this->resolveTeamForRow($row, $teamByName, $teamByCfbdId, $cfbdIdByName, $mappingRowsByCfbdId);

            if (! $team) {
                $skipped++;

                continue;
            }

            $matched++;

            $rating = FpiRating::query()->firstOrNew([
                'team_id' => $team->id,
                'season' => $season,
                'week' => $week,
            ]);

            if ($rating->exists) {
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
            $rating->save();
            if (is_numeric($rating->fpi)) {
                $usable++;
                $usableTeamIds[] = (int) $team->id;
            }
        }

        $this->info("Matched {$matched} rows. Created {$created}, updated {$updated}, skipped {$skipped}.");

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

        if ($this->option('recalculate-metrics')) {
            $this->newLine();

            return $this->call('cfb:calculate-team-metrics', ['--season' => $season]);
        }

        return self::SUCCESS;
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
    protected function buildCfbdMappingIndexes(): array
    {
        $index = [];
        $rowsByCfbdId = [];

        foreach (CfbdTeamMapping::query()->get() as $mapping) {
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
                        $mappedTeam->forceFill(['cfbd_team_id' => $cfbdTeamId])->saveQuietly();
                    }

                    return $mappedTeam;
                }
            }
        }

        $team = Team::query()->where('cfbd_team_id', $cfbdTeamId)->first();
        if ($team instanceof Team) {
            return $team;
        }

        return null;
    }

    protected function normalizeName(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) $value));
        $normalized = str_replace(['’', '\''], '', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    protected function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 3) : null;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
