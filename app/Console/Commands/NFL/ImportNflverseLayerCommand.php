<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ImportNflverseLayerCommand extends Command
{
    protected $signature = 'nfl:import-nflverse-layer
        {dataset : pbp, rosters, depth-charts, injuries, or weekly-stats}
        {file : Path to nflverse CSV or CSV.GZ}
        {--from-season= : First season to import}
        {--to-season= : Last season to import}
        {--limit= : Stop after this many imported rows}
        {--chunk=500 : Upsert batch size}
        {--without-raw-payload : Store mapped fields only and leave raw_payload null}
        {--dry-run : Parse and match rows without writing}';

    protected $description = 'Import rich nflverse NFL layers for play-by-play, rosters, depth charts, injuries, and weekly stats';

    /** @var array<string,Team> */
    private array $teamsByAbbreviation = [];

    /** @var array<string,int|null> */
    private array $gameIdsByNflverseId = [];

    /** @var array<string,array{table:string,key:string,mapper:string}> */
    private array $datasets = [
        'pbp' => ['table' => 'nflverse_pbp_plays', 'key' => 'nflverse_play_key', 'mapper' => 'mapPlayByPlay'],
        'rosters' => ['table' => 'nflverse_rosters', 'key' => 'nflverse_roster_key', 'mapper' => 'mapRoster'],
        'depth-charts' => ['table' => 'nflverse_depth_charts', 'key' => 'nflverse_depth_chart_key', 'mapper' => 'mapDepthChart'],
        'injuries' => ['table' => 'nflverse_injuries', 'key' => 'nflverse_injury_key', 'mapper' => 'mapInjury'],
        'weekly-stats' => ['table' => 'nflverse_weekly_player_stats', 'key' => 'nflverse_weekly_stat_key', 'mapper' => 'mapWeeklyStat'],
    ];

    public function handle(): int
    {
        $dataset = (string) $this->argument('dataset');
        if (! isset($this->datasets[$dataset])) {
            $this->error('Dataset must be one of: '.implode(', ', array_keys($this->datasets)));

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');
        if (! File::exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->teamsByAbbreviation = Team::query()
            ->get()
            ->keyBy(fn (Team $team): string => $this->normalizeTeam($team->abbreviation))
            ->all();

        $config = $this->datasets[$dataset];
        $mapper = $config['mapper'];
        $chunkSize = max(1, (int) $this->option('chunk'));
        $limit = $this->option('limit');
        $limit = $limit === null || $limit === '' ? null : max(1, (int) $limit);
        $now = Carbon::now();

        $reader = $this->openCsv($file);
        $headers = $this->readCsvRow($reader);
        if ($headers === null) {
            $this->closeCsv($reader);
            $this->error('No header row found in nflverse CSV.');

            return self::FAILURE;
        }

        $headers = array_map(fn (string $header): string => trim($header), $headers);
        $buffer = [];
        $rows = 0;
        $imported = 0;
        $skipped = 0;

        while (($values = $this->readCsvRow($reader)) !== null) {
            if ($values === [null] || $values === []) {
                continue;
            }

            $row = $this->combineRow($headers, $values);
            $rows++;

            if (! $this->shouldImport($row)) {
                $skipped++;

                continue;
            }

            $mapped = $this->{$mapper}($row, $now);
            if ($mapped === null) {
                $skipped++;

                continue;
            }

            $buffer[] = $mapped;

            if (count($buffer) >= $chunkSize) {
                $imported += $this->flush($config['table'], $config['key'], $buffer);
                $buffer = [];
            }

            if ($limit !== null && $imported + count($buffer) >= $limit) {
                break;
            }
        }

        $imported += $this->flush($config['table'], $config['key'], $buffer);
        $this->closeCsv($reader);

        $this->info(sprintf(
            'nflverse %s: %d rows read, %d imported/upserted, %d skipped%s.',
            $dataset,
            $rows,
            $imported,
            $skipped,
            $this->option('dry-run') ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,string|null>  $row
     * @return array<string,mixed>|null
     */
    private function mapPlayByPlay(array $row, Carbon $now): ?array
    {
        $nflverseGameId = $this->stringValue($row, 'game_id');
        $playId = $this->stringValue($row, 'play_id');
        $key = $nflverseGameId && $playId
            ? $nflverseGameId.'|'.$playId
            : $this->rowHash($row);

        return [
            'nflverse_play_key' => $this->key($key),
            'nfl_game_id' => $this->gameId($nflverseGameId),
            'nflverse_game_id' => $nflverseGameId,
            'play_id' => $playId,
            'season' => $this->intValue($row, 'season'),
            'week' => $this->intValue($row, 'week'),
            'season_type' => $this->stringValue($row, 'season_type'),
            'home_team' => $this->normalizedTeamValue($row, 'home_team'),
            'away_team' => $this->normalizedTeamValue($row, 'away_team'),
            'possession_team_id' => $this->teamId($row, 'posteam'),
            'possession_team' => $this->normalizedTeamValue($row, 'posteam'),
            'defense_team_id' => $this->teamId($row, 'defteam'),
            'defense_team' => $this->normalizedTeamValue($row, 'defteam'),
            'quarter' => $this->intValue($row, 'qtr'),
            'down' => $this->intValue($row, 'down'),
            'yards_to_go' => $this->intValue($row, 'ydstogo'),
            'yardline_100' => $this->floatValue($row, 'yardline_100'),
            'yards_gained' => $this->floatValue($row, 'yards_gained'),
            'game_seconds_remaining' => $this->floatValue($row, 'game_seconds_remaining'),
            'play_type' => $this->stringValue($row, 'play_type'),
            'description' => $this->textValue($row, 'desc'),
            'epa' => $this->floatValue($row, 'epa'),
            'win_probability' => $this->floatValue($row, 'wp'),
            'win_probability_added' => $this->floatValue($row, 'wpa'),
            'passer_player_id' => $this->stringValue($row, 'passer_player_id'),
            'passer_player_name' => $this->stringValue($row, 'passer_player_name'),
            'rusher_player_id' => $this->stringValue($row, 'rusher_player_id'),
            'rusher_player_name' => $this->stringValue($row, 'rusher_player_name'),
            'receiver_player_id' => $this->stringValue($row, 'receiver_player_id'),
            'receiver_player_name' => $this->stringValue($row, 'receiver_player_name'),
            'is_touchdown' => $this->boolValue($row, 'touchdown'),
            'is_interception' => $this->boolValue($row, 'interception'),
            'is_fumble_lost' => $this->boolValue($row, 'fumble_lost'),
            'is_sack' => $this->boolValue($row, 'sack'),
            'raw_payload' => $this->rawPayload($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string,string|null>  $row
     * @return array<string,mixed>|null
     */
    private function mapRoster(array $row, Carbon $now): ?array
    {
        $season = $this->intValue($row, 'season');
        $team = $this->normalizedFirstValue($row, ['team', 'recent_team']);
        $gsisId = $this->firstStringValue($row, ['gsis_id', 'player_id']);
        $fullName = $this->firstStringValue($row, ['full_name', 'display_name', 'player_display_name']);

        if ($season === null || ($gsisId === null && $fullName === null)) {
            return null;
        }

        return [
            'nflverse_roster_key' => $this->key($season.'|'.$team.'|'.($gsisId ?? $fullName)),
            'season' => $season,
            'team_id' => $this->teamIdFromAbbreviation($team),
            'team' => $team,
            'gsis_id' => $gsisId,
            'espn_id' => $this->stringValue($row, 'espn_id'),
            'pfr_id' => $this->stringValue($row, 'pfr_id'),
            'full_name' => $fullName,
            'first_name' => $this->firstStringValue($row, ['first_name', 'common_first_name']),
            'last_name' => $this->stringValue($row, 'last_name'),
            'position' => $this->stringValue($row, 'position'),
            'jersey_number' => $this->firstStringValue($row, ['jersey_number', 'jersey']),
            'status' => $this->stringValue($row, 'status'),
            'years_exp' => $this->firstIntValue($row, ['years_exp', 'experience']),
            'birth_date' => $this->dateValue($row, 'birth_date'),
            'height' => $this->intValue($row, 'height'),
            'weight' => $this->intValue($row, 'weight'),
            'raw_payload' => $this->rawPayload($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string,string|null>  $row
     * @return array<string,mixed>|null
     */
    private function mapDepthChart(array $row, Carbon $now): ?array
    {
        $season = $this->seasonValue($row);
        $team = $this->normalizedFirstValue($row, ['club_code', 'team']);
        $gsisId = $this->firstStringValue($row, ['gsis_id', 'player_id']);
        $fullName = $this->firstStringValue($row, ['full_name', 'player_name', 'player_display_name']);
        $depthTeam = $this->firstStringValue($row, ['depth_team', 'pos_grp']);
        $depthPosition = $this->firstStringValue($row, ['depth_position', 'pos_abb', 'pos_name']);
        $formation = $this->firstStringValue($row, ['formation', 'pos_grp']);
        $sourceUpdatedAt = $this->timestampValue($row, 'dt');

        if ($season === null || $team === null) {
            return null;
        }

        return [
            'nflverse_depth_chart_key' => $this->key(implode('|', [
                $season,
                $this->intValue($row, 'week'),
                $this->firstStringValue($row, ['game_type', 'season_type']),
                $team,
                $gsisId,
                $fullName,
                $sourceUpdatedAt,
                $depthTeam,
                $depthPosition,
                $formation,
            ])),
            'season' => $season,
            'week' => $this->intValue($row, 'week'),
            'season_type' => $this->firstStringValue($row, ['game_type', 'season_type']),
            'team_id' => $this->teamIdFromAbbreviation($team),
            'team' => $team,
            'gsis_id' => $gsisId,
            'full_name' => $fullName,
            'position' => $this->firstStringValue($row, ['position', 'pos_abb']),
            'depth_team' => $depthTeam,
            'depth_position' => $depthPosition,
            'formation' => $formation,
            'depth_rank' => $this->firstIntValue($row, ['depth_rank', 'rank', 'pos_rank']),
            'source_updated_at' => $sourceUpdatedAt,
            'raw_payload' => $this->rawPayload($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string,string|null>  $row
     * @return array<string,mixed>|null
     */
    private function mapInjury(array $row, Carbon $now): ?array
    {
        $season = $this->seasonValue($row);
        $team = $this->normalizedFirstValue($row, ['team', 'club_code']);
        $gsisId = $this->firstStringValue($row, ['gsis_id', 'player_id']);
        $fullName = $this->firstStringValue($row, ['full_name', 'player_name', 'player_display_name']);

        if ($season === null || $team === null || ($gsisId === null && $fullName === null)) {
            return null;
        }

        return [
            'nflverse_injury_key' => $this->key(implode('|', [
                $season,
                $this->intValue($row, 'week'),
                $this->firstStringValue($row, ['game_type', 'season_type']),
                $team,
                $gsisId,
                $fullName,
                $this->firstStringValue($row, ['date_modified', 'report_date']),
                $this->stringValue($row, 'report_status'),
                $this->stringValue($row, 'practice_status'),
                $this->stringValue($row, 'report_primary_injury'),
            ])),
            'season' => $season,
            'week' => $this->intValue($row, 'week'),
            'season_type' => $this->firstStringValue($row, ['game_type', 'season_type']),
            'team_id' => $this->teamIdFromAbbreviation($team),
            'team' => $team,
            'gsis_id' => $gsisId,
            'full_name' => $fullName,
            'position' => $this->stringValue($row, 'position'),
            'report_primary_injury' => $this->stringValue($row, 'report_primary_injury'),
            'report_status' => $this->stringValue($row, 'report_status'),
            'practice_status' => $this->stringValue($row, 'practice_status'),
            'source_updated_at' => $this->timestampValue($row, 'date_modified'),
            'raw_payload' => $this->rawPayload($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string,string|null>  $row
     * @return array<string,mixed>|null
     */
    private function mapWeeklyStat(array $row, Carbon $now): ?array
    {
        $season = $this->intValue($row, 'season');
        $week = $this->intValue($row, 'week');
        $team = $this->normalizedFirstValue($row, ['team', 'recent_team']);
        $playerId = $this->firstStringValue($row, ['player_id', 'gsis_id']);
        $nflverseGameId = $this->stringValue($row, 'game_id');

        if ($season === null || $week === null || $team === null || $playerId === null) {
            return null;
        }

        return [
            'nflverse_weekly_stat_key' => $this->key(implode('|', [
                $season,
                $week,
                $this->stringValue($row, 'season_type'),
                $nflverseGameId,
                $team,
                $playerId,
            ])),
            'nfl_game_id' => $this->gameId($nflverseGameId),
            'nflverse_game_id' => $nflverseGameId,
            'season' => $season,
            'week' => $week,
            'season_type' => $this->stringValue($row, 'season_type'),
            'team_id' => $this->teamIdFromAbbreviation($team),
            'team' => $team,
            'opponent_team_id' => $this->teamId($row, 'opponent_team'),
            'opponent_team' => $this->normalizedTeamValue($row, 'opponent_team'),
            'player_id' => $playerId,
            'player_name' => $this->stringValue($row, 'player_name'),
            'player_display_name' => $this->stringValue($row, 'player_display_name'),
            'position' => $this->stringValue($row, 'position'),
            'position_group' => $this->stringValue($row, 'position_group'),
            'passing_attempts' => $this->intValue($row, 'attempts'),
            'passing_yards' => $this->intValue($row, 'passing_yards'),
            'passing_touchdowns' => $this->intValue($row, 'passing_tds'),
            'interceptions_thrown' => $this->intValue($row, 'passing_interceptions'),
            'rushing_attempts' => $this->firstIntValue($row, ['carries', 'rushing_attempts']),
            'rushing_yards' => $this->intValue($row, 'rushing_yards'),
            'rushing_touchdowns' => $this->intValue($row, 'rushing_tds'),
            'targets' => $this->intValue($row, 'targets'),
            'receptions' => $this->intValue($row, 'receptions'),
            'receiving_yards' => $this->intValue($row, 'receiving_yards'),
            'receiving_touchdowns' => $this->intValue($row, 'receiving_tds'),
            'fantasy_points_ppr' => $this->floatValue($row, 'fantasy_points_ppr'),
            'raw_payload' => $this->rawPayload($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<int,string>  $headers
     * @param  array<int,string|null>  $values
     * @return array<string,string|null>
     */
    private function combineRow(array $headers, array $values): array
    {
        $row = [];
        foreach ($headers as $index => $header) {
            $value = $values[$index] ?? null;
            $row[$header] = $value === '' ? null : $value;
        }

        return $row;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function flush(string $table, string $key, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        if (! $this->option('dry-run')) {
            $columns = array_keys($rows[0]);
            $updates = array_values(array_diff($columns, [$key, 'created_at']));

            DB::table($table)->upsert($rows, [$key], $updates);
        }

        return count($rows);
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function shouldImport(array $row): bool
    {
        $season = $this->seasonValue($row);
        if ($season === null) {
            return false;
        }

        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($fromSeason !== null && $fromSeason !== '' && $season < (int) $fromSeason) {
            return false;
        }

        if ($toSeason !== null && $toSeason !== '' && $season > (int) $toSeason) {
            return false;
        }

        return true;
    }

    /**
     * @return array{resource:resource,gzip:bool}
     */
    private function openCsv(string $file): array
    {
        $gzip = str_ends_with($file, '.gz');
        $resource = $gzip ? gzopen($file, 'rb') : fopen($file, 'rb');

        if ($resource === false) {
            throw new RuntimeException("Unable to open file: {$file}");
        }

        return ['resource' => $resource, 'gzip' => $gzip];
    }

    /**
     * @param  array{resource:resource,gzip:bool}  $reader
     * @return array<int,string|null>|null
     */
    private function readCsvRow(array $reader): ?array
    {
        $row = $reader['gzip']
            ? fgetcsv($reader['resource'])
            : fgetcsv($reader['resource']);

        return $row === false ? null : $row;
    }

    /**
     * @param  array{resource:resource,gzip:bool}  $reader
     */
    private function closeCsv(array $reader): void
    {
        if ($reader['gzip']) {
            gzclose($reader['resource']);

            return;
        }

        fclose($reader['resource']);
    }

    private function gameId(?string $nflverseGameId): ?int
    {
        if ($nflverseGameId === null) {
            return null;
        }

        if (array_key_exists($nflverseGameId, $this->gameIdsByNflverseId)) {
            return $this->gameIdsByNflverseId[$nflverseGameId];
        }

        $id = Game::query()
            ->where('nflverse_game_id', $nflverseGameId)
            ->value('id');

        $this->gameIdsByNflverseId[$nflverseGameId] = $id ? (int) $id : null;

        return $this->gameIdsByNflverseId[$nflverseGameId];
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function teamId(array $row, string $key): ?int
    {
        return $this->teamIdFromAbbreviation($this->normalizedTeamValue($row, $key));
    }

    private function teamIdFromAbbreviation(?string $abbreviation): ?int
    {
        if ($abbreviation === null) {
            return null;
        }

        return $this->teamsByAbbreviation[$abbreviation]->id ?? null;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function normalizedTeamValue(array $row, string $key): ?string
    {
        $value = $this->stringValue($row, $key);

        return $value === null ? null : $this->normalizeTeam($value);
    }

    /**
     * @param  array<string,string|null>  $row
     * @param  array<int,string>  $keys
     */
    private function normalizedFirstValue(array $row, array $keys): ?string
    {
        $value = $this->firstStringValue($row, $keys);

        return $value === null ? null : $this->normalizeTeam($value);
    }

    private function normalizeTeam(mixed $team): string
    {
        $team = strtoupper(trim((string) $team));

        return [
            'ARZ' => 'ARI',
            'JAC' => 'JAX',
            'LA' => 'LAR',
            'STL' => 'LAR',
            'SD' => 'LAC',
            'OAK' => 'LV',
            'WAS' => 'WSH',
        ][$team] ?? $team;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function stringValue(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function textValue(array $row, string $key): ?string
    {
        return $this->stringValue($row, $key);
    }

    /**
     * @param  array<string,string|null>  $row
     * @param  array<int,string>  $keys
     */
    private function firstStringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->stringValue($row, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function intValue(array $row, string $key): ?int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function seasonValue(array $row): ?int
    {
        $season = $this->intValue($row, 'season');
        if ($season !== null) {
            return $season;
        }

        $timestamp = $this->stringValue($row, 'dt');
        if ($timestamp === null) {
            return null;
        }

        try {
            return (int) Carbon::parse($timestamp)->year;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,string|null>  $row
     * @param  array<int,string>  $keys
     */
    private function firstIntValue(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->intValue($row, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function floatValue(array $row, string $key): ?float
    {
        return is_numeric($row[$key] ?? null) ? (float) $row[$key] : null;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function boolValue(array $row, string $key): ?bool
    {
        $value = strtolower(trim((string) ($row[$key] ?? '')));
        if ($value === '') {
            return null;
        }

        if (in_array($value, ['1', 'true', 't', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'false', 'f', 'no', 'n'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function dateValue(array $row, string $key): ?string
    {
        $value = $this->stringValue($row, $key);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function timestampValue(array $row, string $key): ?string
    {
        $value = $this->stringValue($row, $key);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function rowHash(array $row): string
    {
        return hash('sha256', json_encode($row));
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function rawPayload(array $row): ?string
    {
        return $this->option('without-raw-payload') ? null : json_encode($row);
    }

    private function key(string $value): string
    {
        return hash('sha256', $value);
    }
}
