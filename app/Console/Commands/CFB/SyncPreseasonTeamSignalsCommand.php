<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\PreseasonTeamSignal;
use App\Models\CFB\Team;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncPreseasonTeamSignalsCommand extends Command
{
    protected $signature = 'cfb:sync-preseason-team-signals
        {--season= : Season to sync (defaults to configured CFB season)}
        {--team= : Optional CFBD/internal team name filter}
        {--skip-returning-production : Skip CFBD returning production}
        {--skip-transfers : Skip CFBD transfer portal summary}
        {--skip-talent : Skip CFBD talent composite}
        {--skip-recruiting : Skip CFBD team recruiting rankings}
        {--dry-run : Fetch and summarize without writing}';

    protected $description = 'Sync CFB preseason team signal inputs for returning production, transfers, talent, and recruiting';

    /**
     * @var array<string, Team>|null
     */
    protected ?array $teamNameIndex = null;

    /**
     * @var Collection<int, Team>|null
     */
    protected ?Collection $teamCfbdIdIndex = null;

    /**
     * @var array<string, int>|null
     */
    protected ?array $mappingNameIndex = null;

    public function handle(CollegeFootballDataService $service): int
    {
        $season = (int) ($this->option('season') ?: config('cfb.season.default', date('Y')));
        $teamFilter = $this->option('team') ? trim((string) $this->option('team')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Syncing CFB preseason team signals for {$season}...");

        $stats = [
            'returning' => 0,
            'transfers' => 0,
            'talent' => 0,
            'recruiting' => 0,
            'skipped' => 0,
        ];

        if (! $this->option('skip-returning-production')) {
            $stats['returning'] = $this->syncReturningProduction($service, $season, $teamFilter, $dryRun, $stats['skipped']);
        }

        if (! $this->option('skip-transfers')) {
            $stats['transfers'] = $this->syncTransferPortal($service, $season, $dryRun, $stats['skipped']);
        }

        if (! $this->option('skip-talent')) {
            $stats['talent'] = $this->syncTalent($service, $season, $dryRun, $stats['skipped']);
        }

        if (! $this->option('skip-recruiting')) {
            $stats['recruiting'] = $this->syncRecruiting($service, $season, $teamFilter, $dryRun, $stats['skipped']);
        }

        $this->info(sprintf(
            'Processed returning=%d, transfer teams=%d, talent=%d, recruiting=%d, skipped=%d%s.',
            $stats['returning'],
            $stats['transfers'],
            $stats['talent'],
            $stats['recruiting'],
            $stats['skipped'],
            $dryRun ? ' (dry run)' : ''
        ));

        return self::SUCCESS;
    }

    protected function syncReturningProduction(
        CollegeFootballDataService $service,
        int $season,
        ?string $teamFilter,
        bool $dryRun,
        int &$skipped
    ): int {
        $rows = $service->getReturningProduction($season, $teamFilter);
        $processed = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }

            $team = $this->resolveTeam($row);

            if (! $team) {
                $skipped++;

                continue;
            }

            $processed++;

            $this->updateSignal($team, $season, [
                'returning_percent_ppa' => $this->floatOrNull(data_get($row, 'percentPPA')),
                'returning_percent_passing_ppa' => $this->floatOrNull(data_get($row, 'percentPassingPPA')),
                'returning_percent_rushing_ppa' => $this->floatOrNull(data_get($row, 'percentRushingPPA')),
                'returning_percent_receiving_ppa' => $this->floatOrNull(data_get($row, 'percentReceivingPPA')),
                'returning_usage' => $this->floatOrNull(data_get($row, 'usage')),
                'returning_passing_usage' => $this->floatOrNull(data_get($row, 'passingUsage')),
                'returning_rushing_usage' => $this->floatOrNull(data_get($row, 'rushingUsage')),
                'returning_receiving_usage' => $this->floatOrNull(data_get($row, 'receivingUsage')),
                'returning_total_ppa' => $this->floatOrNull(data_get($row, 'totalPPA')),
                'returning_total_passing_ppa' => $this->floatOrNull(data_get($row, 'totalPassingPPA')),
                'returning_total_rushing_ppa' => $this->floatOrNull(data_get($row, 'totalRushingPPA')),
                'returning_total_receiving_ppa' => $this->floatOrNull(data_get($row, 'totalReceivingPPA')),
                'returning_production_payload' => $row,
                'data_quality_status' => 'partial',
                'synced_at' => now(),
            ], $dryRun);
        }

        return $processed;
    }

    protected function syncTransferPortal(
        CollegeFootballDataService $service,
        int $season,
        bool $dryRun,
        int &$skipped
    ): int {
        $rows = $service->getTransferPortal($season);
        $summaries = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }

            foreach (['incoming' => 'destination', 'outgoing' => 'origin'] as $direction => $teamKey) {
                $team = $this->resolveTeam(['team' => data_get($row, $teamKey)]);

                if (! $team) {
                    continue;
                }

                $teamId = (int) $team->id;
                $summaries[$teamId] ??= $this->emptyTransferSummary();
                $this->addTransferRow($summaries[$teamId], $direction, $row);
            }
        }

        foreach ($summaries as $teamId => $summary) {
            /** @var Team|null $team */
            $team = $this->teamCfbdIdIndex()->firstWhere('id', $teamId) ?? Team::query()->find($teamId);

            if (! $team) {
                $skipped++;

                continue;
            }

            $this->updateSignal($team, $season, [
                'incoming_transfer_count' => $summary['incoming_count'],
                'outgoing_transfer_count' => $summary['outgoing_count'],
                'incoming_transfer_value' => $this->roundFloat($summary['incoming_value']),
                'outgoing_transfer_value' => $this->roundFloat($summary['outgoing_value']),
                'transfer_net_value' => $this->roundFloat($summary['incoming_value'] - $summary['outgoing_value']),
                'transfer_qb_net_value' => $this->positionNetValue($summary['positions'], 'QB'),
                'transfer_ol_net_value' => $this->positionNetValue($summary['positions'], 'OL'),
                'transfer_dl_net_value' => $this->positionNetValue($summary['positions'], 'DL'),
                'transfer_wr_net_value' => $this->positionNetValue($summary['positions'], 'WR'),
                'transfer_cb_net_value' => $this->positionNetValue($summary['positions'], 'CB'),
                'transfer_position_summary' => $summary['positions'],
                'transfer_portal_payload' => $summary['payload'],
                'data_quality_status' => 'partial',
                'synced_at' => now(),
            ], $dryRun);
        }

        return count($summaries);
    }

    protected function syncTalent(CollegeFootballDataService $service, int $season, bool $dryRun, int &$skipped): int
    {
        $rows = $service->getTeamTalent($season);
        $rank = 0;
        $processed = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }

            $team = $this->resolveTeam($row);

            if (! $team) {
                $skipped++;

                continue;
            }

            $rank++;
            $processed++;

            $this->updateSignal($team, $season, [
                'talent_composite' => $this->floatOrNull(data_get($row, 'talent')),
                'talent_rank' => $this->intOrNull(data_get($row, 'rank')) ?? $rank,
                'talent_payload' => $row,
                'data_quality_status' => 'partial',
                'synced_at' => now(),
            ], $dryRun);
        }

        return $processed;
    }

    protected function syncRecruiting(
        CollegeFootballDataService $service,
        int $season,
        ?string $teamFilter,
        bool $dryRun,
        int &$skipped
    ): int {
        $rows = $service->getTeamRecruitingRankings($season, $teamFilter);
        $processed = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }

            $team = $this->resolveTeam($row);

            if (! $team) {
                $skipped++;

                continue;
            }

            $processed++;

            $this->updateSignal($team, $season, [
                'recruiting_rank' => $this->intOrNull(data_get($row, 'rank')),
                'recruiting_points' => $this->floatOrNull(data_get($row, 'points')),
                'recruiting_avg_rating' => $this->floatOrNull(data_get($row, 'avgRating'), 4),
                'recruiting_payload' => $row,
                'data_quality_status' => 'partial',
                'synced_at' => now(),
            ], $dryRun);
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function updateSignal(Team $team, int $season, array $attributes, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        PreseasonTeamSignal::query()->updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            $attributes
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveTeam(array $row): ?Team
    {
        $cfbdTeamId = $this->intOrNull(data_get($row, 'teamId') ?? data_get($row, 'id'));

        if ($cfbdTeamId !== null) {
            $team = $this->teamCfbdIdIndex()->get($cfbdTeamId);

            if ($team instanceof Team) {
                return $team;
            }
        }

        foreach ([
            data_get($row, 'team'),
            data_get($row, 'school'),
            data_get($row, 'destination'),
            data_get($row, 'origin'),
        ] as $candidate) {
            $normalized = $this->normalizeName($candidate);

            if ($normalized === '') {
                continue;
            }

            $team = $this->teamNameIndex()[$normalized] ?? null;
            if ($team instanceof Team) {
                return $team;
            }

            $mappedCfbdTeamId = $this->mappingNameIndex()[$normalized] ?? null;
            if ($mappedCfbdTeamId !== null) {
                $team = $this->teamCfbdIdIndex()->get($mappedCfbdTeamId);

                if ($team instanceof Team) {
                    return $team;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, Team>
     */
    protected function teamNameIndex(): array
    {
        if ($this->teamNameIndex !== null) {
            return $this->teamNameIndex;
        }

        $index = [];

        foreach (Team::query()->get() as $team) {
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

        $this->teamNameIndex = $index;

        return $this->teamNameIndex;
    }

    /**
     * @return Collection<int, Team>
     */
    protected function teamCfbdIdIndex(): Collection
    {
        if ($this->teamCfbdIdIndex !== null) {
            return $this->teamCfbdIdIndex;
        }

        $this->teamCfbdIdIndex = Team::query()
            ->whereNotNull('cfbd_team_id')
            ->get()
            ->keyBy(fn (Team $team): int => (int) $team->cfbd_team_id);

        return $this->teamCfbdIdIndex;
    }

    /**
     * @return array<string, int>
     */
    protected function mappingNameIndex(): array
    {
        if ($this->mappingNameIndex !== null) {
            return $this->mappingNameIndex;
        }

        $index = [];

        foreach (CfbdTeamMapping::query()->get() as $mapping) {
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

        $this->mappingNameIndex = $index;

        return $this->mappingNameIndex;
    }

    /**
     * @return array{
     *     incoming_count: int,
     *     outgoing_count: int,
     *     incoming_value: float,
     *     outgoing_value: float,
     *     positions: array<string, array<string, int|float>>,
     *     payload: array<int, array<string, mixed>>
     * }
     */
    protected function emptyTransferSummary(): array
    {
        return [
            'incoming_count' => 0,
            'outgoing_count' => 0,
            'incoming_value' => 0.0,
            'outgoing_value' => 0.0,
            'positions' => [],
            'payload' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $row
     */
    protected function addTransferRow(array &$summary, string $direction, array $row): void
    {
        $position = $this->positionBucket(data_get($row, 'position'));
        $value = $this->transferValue($row);

        $countKey = "{$direction}_count";
        $valueKey = "{$direction}_value";

        $summary[$countKey]++;
        $summary[$valueKey] += $value;
        $summary['payload'][] = $row;
        $summary['positions'][$position] ??= [
            'incoming_count' => 0,
            'outgoing_count' => 0,
            'incoming_value' => 0.0,
            'outgoing_value' => 0.0,
            'net_value' => 0.0,
        ];
        $summary['positions'][$position][$countKey]++;
        $summary['positions'][$position][$valueKey] += $value;
        $summary['positions'][$position]['net_value'] =
            $summary['positions'][$position]['incoming_value'] - $summary['positions'][$position]['outgoing_value'];
    }

    protected function positionBucket(mixed $position): string
    {
        $normalized = strtoupper(trim((string) $position));

        return match (true) {
            in_array($normalized, ['QB'], true) => 'QB',
            in_array($normalized, ['C', 'G', 'OG', 'OT', 'OL'], true) => 'OL',
            in_array($normalized, ['DL', 'DT', 'DE', 'EDGE'], true) => 'DL',
            in_array($normalized, ['WR'], true) => 'WR',
            in_array($normalized, ['CB'], true) => 'CB',
            $normalized !== '' => $normalized,
            default => 'UNK',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function transferValue(array $row): float
    {
        $rating = $this->floatOrNull(data_get($row, 'rating'));

        if ($rating !== null) {
            return $rating;
        }

        return (float) ($this->floatOrNull(data_get($row, 'stars')) ?? 0.0);
    }

    /**
     * @param  array<string, array<string, int|float>>  $positions
     */
    protected function positionNetValue(array $positions, string $position): float
    {
        return $this->roundFloat((float) ($positions[$position]['net_value'] ?? 0.0));
    }

    protected function normalizeName(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) $value));
        $normalized = str_replace(['’', '\''], '', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    protected function floatOrNull(mixed $value, int $precision = 3): ?float
    {
        return is_numeric($value) ? $this->roundFloat((float) $value, $precision) : null;
    }

    protected function roundFloat(float $value, int $precision = 3): float
    {
        return round($value, $precision);
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
