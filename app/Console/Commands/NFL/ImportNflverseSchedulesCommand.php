<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameWeather;
use App\Models\NFL\Team;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportNflverseSchedulesCommand extends Command
{
    protected $signature = 'nfl:import-nflverse-schedules
        {file : Path to nflverse schedules CSV}
        {--from-season= : First season to import}
        {--to-season= : Last season to import}
        {--include-preseason : Import preseason rows too}
        {--dry-run : Parse and match rows without writing}';

    protected $description = 'Import nflverse schedules into NFL games, weather, coach, QB, and closing-line snapshots';

    /** @var array<string,Team> */
    private array $teamsByAbbreviation = [];

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! File::exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->teamsByAbbreviation = Team::query()
            ->get()
            ->keyBy(fn (Team $team): string => $this->normalizeTeam($team->abbreviation))
            ->all();

        $rows = $this->readCsv($file);
        if ($rows === []) {
            $this->error('No rows found in nflverse schedules CSV.');

            return self::FAILURE;
        }

        $imported = 0;
        $updated = 0;
        $snapshots = 0;
        $weather = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! $this->shouldImport($row)) {
                $skipped++;

                continue;
            }

            $homeTeam = $this->teamFromRow($row, 'home_team');
            $awayTeam = $this->teamFromRow($row, 'away_team');
            $nflverseGameId = $this->stringValue($row, 'game_id');

            if (! $homeTeam || ! $awayTeam || $nflverseGameId === null) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $imported++;

                continue;
            }

            $existing = $this->findExistingGame($row, $homeTeam, $awayTeam, $nflverseGameId);

            $attributes = $this->gameAttributes($row, $homeTeam, $awayTeam, $nflverseGameId);
            if ($existing) {
                unset($attributes['espn_event_id'], $attributes['espn_uid']);
                $existing->fill($attributes);
                $existing->nflverse_game_id = $nflverseGameId;
                $existing->save();
                $game = $existing;
            } else {
                $game = Game::query()->create([
                    'nflverse_game_id' => $nflverseGameId,
                    ...$attributes,
                ]);
            }

            app(SportEventIdentitySynchronizer::class)->sync('nfl', $game);
            $existing ? $updated++ : $imported++;

            if ($this->storeClosingLineSnapshot($game, $row)) {
                $snapshots++;
            }

            if ($this->storeWeather($game, $row)) {
                $weather++;
            }
        }

        $this->info(sprintf(
            'nflverse schedules: %d rows, %d imported, %d updated, %d closing snapshots, %d weather rows, %d skipped.',
            count($rows),
            $imported,
            $updated,
            $snapshots,
            $weather,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function findExistingGame(array $row, Team $homeTeam, Team $awayTeam, string $nflverseGameId): ?Game
    {
        $espnId = $this->stringValue($row, 'espn');

        $query = Game::query()
            ->where('nflverse_game_id', $nflverseGameId)
            ->orWhere('espn_event_id', 'nflverse:'.$nflverseGameId);

        if ($espnId !== null) {
            $query->orWhere('espn_event_id', $espnId);
        }

        $existing = $query->first();
        if ($existing) {
            return $existing;
        }

        $kickoff = $this->kickoff($row);
        if ($kickoff === null) {
            return null;
        }

        $candidates = Game::query()
            ->where('season', $this->intValue($row, 'season'))
            ->whereDate('game_date', $kickoff->toDateString())
            ->where('home_team_id', $homeTeam->id)
            ->where('away_team_id', $awayTeam->id)
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $homeScore = $this->intValue($row, 'home_score');
        $awayScore = $this->intValue($row, 'away_score');

        return $candidates->first(fn (Game $game): bool => $homeScore !== null
            && $awayScore !== null
            && (int) $game->home_score === $homeScore
            && (int) $game->away_score === $awayScore);
    }

    /**
     * @return array<int,array<string,string|null>>
     */
    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $headers = array_map(fn (string $header): string => trim($header), $headers);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $value = $values[$index] ?? null;
                $row[$header] = $value === '' ? null : $value;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function shouldImport(array $row): bool
    {
        $season = $this->intValue($row, 'season');
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

        return $this->option('include-preseason') || $this->seasonType($row) !== '1';
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function teamFromRow(array $row, string $key): ?Team
    {
        $abbreviation = $this->normalizeTeam($row[$key] ?? null);

        return $this->teamsByAbbreviation[$abbreviation] ?? null;
    }

    /**
     * @param  array<string,string|null>  $row
     * @return array<string,mixed>
     */
    private function gameAttributes(array $row, Team $homeTeam, Team $awayTeam, string $nflverseGameId): array
    {
        $kickoff = $this->kickoff($row);
        $seasonType = $this->seasonType($row);
        $status = $this->intValue($row, 'home_score') !== null && $this->intValue($row, 'away_score') !== null
            ? 'STATUS_FINAL'
            : 'STATUS_SCHEDULED';
        $oddsData = $this->oddsPayload($row, $homeTeam, $awayTeam, $kickoff);

        return [
            'espn_event_id' => 'nflverse:'.$nflverseGameId,
            'espn_uid' => $this->stringValue($row, 'espn') !== null
                ? 's:20~l:28~e:'.$this->stringValue($row, 'espn')
                : 'nflverse:'.$nflverseGameId,
            'season' => $this->intValue($row, 'season'),
            'week' => $this->intValue($row, 'week') ?? 0,
            'season_type' => $seasonType,
            'game_date' => $kickoff?->toDateString() ?? $this->stringValue($row, 'gameday'),
            'game_time' => $kickoff?->format('H:i:s') ?? '00:00:00',
            'name' => $this->teamDisplayName($awayTeam).' at '.$this->teamDisplayName($homeTeam),
            'short_name' => $awayTeam->abbreviation.' @ '.$homeTeam->abbreviation,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'home_score' => $this->intValue($row, 'home_score'),
            'away_score' => $this->intValue($row, 'away_score'),
            'status' => $status,
            'neutral_site' => $this->boolValue($row, 'location'),
            'venue_name' => $this->stringValue($row, 'stadium'),
            'stadium_id' => $this->stringValue($row, 'stadium_id'),
            'roof' => $this->stringValue($row, 'roof'),
            'surface' => $this->stringValue($row, 'surface'),
            'home_rest' => $this->intValue($row, 'home_rest'),
            'away_rest' => $this->intValue($row, 'away_rest'),
            'division_game' => $this->boolValue($row, 'div_game'),
            'home_qb_id' => $this->stringValue($row, 'home_qb_id'),
            'home_qb_name' => $this->stringValue($row, 'home_qb_name'),
            'away_qb_id' => $this->stringValue($row, 'away_qb_id'),
            'away_qb_name' => $this->stringValue($row, 'away_qb_name'),
            'home_coach' => $this->stringValue($row, 'home_coach'),
            'away_coach' => $this->stringValue($row, 'away_coach'),
            'odds_api_event_id' => 'nflverse:'.$nflverseGameId,
            'odds_data' => $oddsData,
            'odds_updated_at' => $kickoff,
        ];
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function seasonType(array $row): string
    {
        return match (strtoupper((string) ($row['game_type'] ?? ''))) {
            'PRE' => '1',
            'REG' => '2',
            default => '3',
        };
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function kickoff(array $row): ?Carbon
    {
        $date = $this->stringValue($row, 'gameday');
        if ($date === null) {
            return null;
        }

        $time = $this->stringValue($row, 'gametime') ?? '00:00';

        return Carbon::parse($date.' '.$time);
    }

    /**
     * @param  array<string,string|null>  $row
     */
    private function storeClosingLineSnapshot(Game $game, array $row): bool
    {
        if (! is_numeric($row['spread_line'] ?? null) && ! is_numeric($row['total_line'] ?? null)) {
            return false;
        }

        $kickoff = $this->kickoff($row);
        $oddsData = $this->oddsPayload($row, $game->homeTeam, $game->awayTeam, $kickoff);
        $capturedAt = $kickoff?->copy()->subMinutes(5) ?? Carbon::parse($game->game_date);

        GameOddsSnapshot::query()->updateOrCreate([
            'sport' => 'nfl',
            'game_table' => $game->getTable(),
            'game_id' => $game->id,
            'source' => 'nflverse',
            'bookmaker_key' => 'nflverse_closing',
            'captured_at' => $capturedAt,
        ], [
            'odds_api_event_id' => 'nflverse:'.$game->nflverse_game_id,
            'bookmaker_title' => 'nflverse closing line',
            'commence_time' => $kickoff,
            'payload_hash' => hash('sha256', json_encode($oddsData)),
            'odds_data' => $oddsData,
            'market_context' => [
                'source' => 'nflverse_schedules',
                'line_type' => 'closing',
                'spread_line' => $this->floatValue($row, 'spread_line'),
                'total_line' => $this->floatValue($row, 'total_line'),
            ],
        ]);

        return true;
    }

    private function storeWeather(Game $game, array $row): bool
    {
        if (
            $this->stringValue($row, 'roof') === null
            && $this->floatValue($row, 'temp') === null
            && $this->floatValue($row, 'wind') === null
        ) {
            return false;
        }

        GameWeather::query()->updateOrCreate(
            ['game_id' => $game->id],
            [
                'provider' => 'nflverse',
                'observed_at' => $this->kickoff($row),
                'temperature_f' => $this->floatValue($row, 'temp'),
                'wind_speed_mph' => $this->floatValue($row, 'wind'),
                'is_indoor' => in_array(strtolower((string) $this->stringValue($row, 'roof')), ['closed', 'dome'], true),
                'raw_payload' => [
                    'roof' => $this->stringValue($row, 'roof'),
                    'surface' => $this->stringValue($row, 'surface'),
                    'stadium' => $this->stringValue($row, 'stadium'),
                    'stadium_id' => $this->stringValue($row, 'stadium_id'),
                    'source' => 'nflverse_schedules',
                ],
            ]
        );

        return true;
    }

    private function oddsPayload(array $row, ?Team $homeTeam, ?Team $awayTeam, ?Carbon $kickoff): array
    {
        $home = (string) ($homeTeam?->abbreviation ?? $this->stringValue($row, 'home_team') ?? 'HOME');
        $away = (string) ($awayTeam?->abbreviation ?? $this->stringValue($row, 'away_team') ?? 'AWAY');
        $markets = [];
        $spread = $this->floatValue($row, 'spread_line');
        $total = $this->floatValue($row, 'total_line');

        if ($spread !== null) {
            $markets[] = [
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => $home, 'price' => $this->intValue($row, 'home_spread_odds') ?? -110, 'point' => $spread],
                    ['name' => $away, 'price' => $this->intValue($row, 'away_spread_odds') ?? -110, 'point' => -$spread],
                ],
            ];
        }

        if ($total !== null) {
            $markets[] = [
                'key' => 'totals',
                'outcomes' => [
                    ['name' => 'Over', 'price' => $this->intValue($row, 'over_odds') ?? -110, 'point' => $total],
                    ['name' => 'Under', 'price' => $this->intValue($row, 'under_odds') ?? -110, 'point' => $total],
                ],
            ];
        }

        return [
            'event_id' => 'nflverse:'.$this->stringValue($row, 'game_id'),
            'commence_time' => $kickoff?->toIso8601String(),
            'home_team' => $home,
            'away_team' => $away,
            'bookmakers' => [[
                'key' => 'nflverse_closing',
                'title' => 'nflverse closing line',
                'markets' => $markets,
            ]],
            'market_context' => [
                'source' => 'nflverse_schedules',
                'line_type' => 'closing',
            ],
        ];
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
            'WSH' => 'WSH',
            'WAS' => 'WSH',
        ][$team] ?? $team;
    }

    private function teamDisplayName(Team $team): string
    {
        $displayName = trim((string) ($team->display_name ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        return trim($team->location.' '.$team->name);
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
    private function intValue(array $row, string $key): ?int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
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
    private function boolValue(array $row, string $key): bool
    {
        $value = strtolower(trim((string) ($row[$key] ?? '')));

        return in_array($value, ['1', 'true', 't', 'yes', 'y', 'neutral'], true);
    }
}
