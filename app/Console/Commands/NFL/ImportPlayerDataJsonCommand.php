<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\Play;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ImportPlayerDataJsonCommand extends Command
{
    protected $signature = 'nfl:import-player-data-json
                            {--player-data=/Users/bey/Downloads/nfl_player_data/nfl_player_data.json : Path to nfl_player_data.json}
                            {--player-stats=/Users/bey/Downloads/nfl_player_data/nfl_player_stats.json : Path to nfl_player_stats.json}
                            {--player-season-stats=/Users/bey/Downloads/nfl_player_data/nfl_player_season_stats.json : Path to nfl_player_season_stats.json}
                            {--plays=/Users/bey/Downloads/nfl_player_data/nfl_plays.json : Path to nfl_plays.json}
                            {--team-stats=/Users/bey/Downloads/nfl_player_data/nfl_team_stats.json : Path to nfl_team_stats.json}
                            {--chunk=1000 : Number of rows per write chunk}';

    protected $description = 'Import NFL players, plays, and stats from JSON exports';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        $paths = [
            'player_data' => (string) $this->option('player-data'),
            'player_stats' => (string) $this->option('player-stats'),
            'player_season_stats' => (string) $this->option('player-season-stats'),
            'plays' => (string) $this->option('plays'),
            'team_stats' => (string) $this->option('team-stats'),
        ];

        foreach ($paths as $name => $path) {
            if (! is_file($path)) {
                $this->error("Missing {$name} file: {$path}");

                return self::FAILURE;
            }
        }

        $this->info('Loading JSON files...');
        $playersData = $this->loadJsonArray($paths['player_data']);
        $playerStatsData = $this->loadJsonArray($paths['player_stats']);
        $playerSeasonStatsData = $this->loadJsonArray($paths['player_season_stats']);
        $playsData = $this->loadJsonArray($paths['plays']);
        $teamStatsData = $this->loadJsonArray($paths['team_stats']);

        $this->line('player_data rows: '.count($playersData));
        $this->line('player_stats rows: '.count($playerStatsData));
        $this->line('player_season_stats rows: '.count($playerSeasonStatsData));
        $this->line('plays rows: '.count($playsData));
        $this->line('team_stats rows: '.count($teamStatsData));

        $teamIds = Team::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $teamIdLookup = array_fill_keys($teamIds, true);

        $gameLookup = $this->buildGameLookup();
        $gameRows = Game::query()->get(['id', 'home_team_id', 'away_team_id']);
        $gameHomeAwayLookup = [];
        foreach ($gameRows as $gameRow) {
            $gameHomeAwayLookup[(int) $gameRow->id] = [
                'home' => (int) $gameRow->home_team_id,
                'away' => (int) $gameRow->away_team_id,
            ];
        }

        $this->info('Importing players...');
        $playerCounts = $this->importPlayers($playersData, $teamIdLookup, $chunkSize);
        $this->line("Players upserted: {$playerCounts['upserted']}, skipped: {$playerCounts['skipped']}");

        $playerIdLookup = Player::query()->pluck('id', 'espn_id')
            ->mapWithKeys(fn ($id, $espnId) => [(string) $espnId => (int) $id])
            ->all();

        $this->info('Replacing plays/player_stats/team_stats tables...');
        Schema::disableForeignKeyConstraints();
        try {
            Play::query()->truncate();
            PlayerStat::query()->truncate();
            TeamStat::query()->truncate();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Importing plays...');
        $playCounts = $this->importPlays($playsData, $gameLookup, $teamIdLookup, $chunkSize);
        $this->line("Plays inserted: {$playCounts['inserted']}, skipped: {$playCounts['skipped']}");

        $this->info('Importing team stats...');
        $teamStatCounts = $this->importTeamStats($teamStatsData, $gameLookup, $teamIdLookup, $gameHomeAwayLookup, $chunkSize);
        $this->line("Team stats upserted: {$teamStatCounts['upserted']}, skipped: {$teamStatCounts['skipped']}");

        $this->info('Importing player stats...');
        $playerStatCounts = $this->importPlayerStats(
            $playerStatsData,
            $gameLookup,
            $teamIdLookup,
            $playerIdLookup,
            $chunkSize
        );
        $this->line("Player stats upserted: {$playerStatCounts['upserted']}, skipped: {$playerStatCounts['skipped']}");

        if (! empty($playerSeasonStatsData)) {
            $this->warn('player_season_stats file is not empty, but no nfl_player_season_stats table exists. Data not imported.');
        }

        $this->info('NFL player JSON import complete.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadJsonArray(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Unable to read file: {$path}");
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON array in file: {$path}");
        }

        return $decoded;
    }

    /**
     * @return array<string, int>
     */
    protected function buildGameLookup(): array
    {
        $lookup = [];
        $games = Game::query()
            ->with(['homeTeam:id,abbreviation', 'awayTeam:id,abbreviation'])
            ->get();

        foreach ($games as $game) {
            $home = strtoupper((string) optional($game->homeTeam)->abbreviation);
            $away = strtoupper((string) optional($game->awayTeam)->abbreviation);
            if ($home === '' || $away === '') {
                continue;
            }

            $date = $game->game_date?->format('Ymd');
            if (! $date) {
                continue;
            }

            $lookup[$date.'_'.$away.'@'.$home] = (int) $game->id;
        }

        return $lookup;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, true>  $teamIdLookup
     * @return array{upserted: int, skipped: int}
     */
    protected function importPlayers(array $rows, array $teamIdLookup, int $chunkSize): array
    {
        $buffer = [];
        $upserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $espnId = isset($row['playerID']) ? trim((string) $row['playerID']) : '';
            if ($espnId === '') {
                $skipped++;
                continue;
            }

            $fullName = trim((string) ($row['espnName'] ?? $row['longName'] ?? ''));
            [$firstName, $lastName] = $this->splitName($fullName);

            $teamId = isset($row['teamID']) ? (int) $row['teamID'] : null;
            if ($teamId !== null && ! isset($teamIdLookup[$teamId])) {
                $teamId = null;
            }

            $buffer[] = [
                'espn_id' => $espnId,
                'team_id' => $teamId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName !== '' ? $fullName : null,
                'jersey_number' => $this->nullableString($row['jerseyNum'] ?? null),
                'position' => $this->nullableString($row['pos'] ?? null),
                'height' => $this->nullableString($row['height'] ?? null),
                'weight' => $this->nullableInt($row['weight'] ?? null),
                'age' => $this->nullableInt($row['age'] ?? null),
                'experience' => $this->nullableInt($row['exp'] ?? null),
                'college' => $this->nullableString($row['school'] ?? null),
                'status' => $this->nullableString($row['injury_designation'] ?? null),
                'headshot_url' => $this->nullableString($row['espnHeadshot'] ?? null),
                'created_at' => $this->nullableString($row['created_at'] ?? null),
                'updated_at' => $this->nullableString($row['updated_at'] ?? null),
            ];

            if (count($buffer) >= $chunkSize) {
                Player::query()->upsert($buffer, ['espn_id'], [
                    'team_id',
                    'first_name',
                    'last_name',
                    'full_name',
                    'jersey_number',
                    'position',
                    'height',
                    'weight',
                    'age',
                    'experience',
                    'college',
                    'status',
                    'headshot_url',
                    'created_at',
                    'updated_at',
                ]);
                $upserted += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            Player::query()->upsert($buffer, ['espn_id'], [
                'team_id',
                'first_name',
                'last_name',
                'full_name',
                'jersey_number',
                'position',
                'height',
                'weight',
                'age',
                'experience',
                'college',
                'status',
                'headshot_url',
                'created_at',
                'updated_at',
            ]);
            $upserted += count($buffer);
        }

        return ['upserted' => $upserted, 'skipped' => $skipped];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $gameLookup
     * @param  array<int, true>  $teamIdLookup
     * @return array{inserted: int, skipped: int}
     */
    protected function importPlays(array $rows, array $gameLookup, array $teamIdLookup, int $chunkSize): array
    {
        $buffer = [];
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $gameKey = strtoupper((string) ($row['game_id'] ?? ''));
            $gameId = $gameLookup[$gameKey] ?? null;
            if (! $gameId) {
                $skipped++;
                continue;
            }

            $teamId = isset($row['team_id']) ? (int) $row['team_id'] : null;
            $possessionTeamId = ($teamId !== null && isset($teamIdLookup[$teamId])) ? $teamId : null;

            $isScoring = $this->toBool($row['scoring_play'] ?? null)
                || $this->toBool($row['touchdown'] ?? null)
                || ((float) ($row['score_value'] ?? 0) > 0);

            $description = $this->nullableString($row['description'] ?? null);

            $buffer[] = [
                'game_id' => $gameId,
                'espn_play_id' => $this->nullableString($row['play_id'] ?? $row['id'] ?? null),
                'sequence_number' => $this->nullableInt($row['sequence_number'] ?? $row['id'] ?? null),
                'period' => $this->nullableInt($row['quarter'] ?? null),
                'clock' => $this->nullableString($row['time'] ?? null),
                'play_type' => $this->nullableString($row['play_type'] ?? null),
                'play_text' => $description,
                'down' => $this->nullableInt($row['down'] ?? null),
                'distance' => $this->nullableInt($row['distance'] ?? null),
                'yards_to_endzone' => $this->nullableInt($row['start_yards_to_endzone'] ?? null),
                'yards_gained' => $this->nullableInt($row['yards_gained'] ?? null),
                'is_scoring_play' => $isScoring,
                'is_turnover' => $this->toBool($row['turnover'] ?? null),
                'is_penalty' => str_contains(strtolower((string) ($row['play_type'] ?? '')), 'penalty')
                    || str_contains(strtolower($description ?? ''), 'penalty'),
                'home_score' => $this->nullableInt($row['home_score'] ?? null),
                'away_score' => $this->nullableInt($row['away_score'] ?? null),
                'possession_team_id' => $possessionTeamId,
                'created_at' => $this->nullableString($row['created_at'] ?? null),
                'updated_at' => $this->nullableString($row['updated_at'] ?? null),
            ];

            if (count($buffer) >= $chunkSize) {
                Play::query()->insert($buffer);
                $inserted += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            Play::query()->insert($buffer);
            $inserted += count($buffer);
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $gameLookup
     * @param  array<int, true>  $teamIdLookup
     * @param  array<int, array{home: int, away: int}>  $gameHomeAwayLookup
     * @return array{upserted: int, skipped: int}
     */
    protected function importTeamStats(
        array $rows,
        array $gameLookup,
        array $teamIdLookup,
        array $gameHomeAwayLookup,
        int $chunkSize
    ): array {
        $buffer = [];
        $upserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $gameKey = strtoupper((string) ($row['game_id'] ?? ''));
            $gameId = $gameLookup[$gameKey] ?? null;
            $teamId = isset($row['team_id']) ? (int) $row['team_id'] : null;

            if (! $gameId || $teamId === null || ! isset($teamIdLookup[$teamId])) {
                $skipped++;
                continue;
            }

            $homeAway = $gameHomeAwayLookup[$gameId] ?? null;
            if (! $homeAway) {
                $skipped++;
                continue;
            }

            $teamType = $teamId === $homeAway['home'] ? 'home' : ($teamId === $homeAway['away'] ? 'away' : null);
            if (! $teamType) {
                $skipped++;
                continue;
            }

            [$passingCompletions, $passingAttempts] = $this->parseFraction($row['pass_completions_and_attempts'] ?? null);
            [$thirdDownConversions, $thirdDownAttempts] = $this->parseFraction($row['third_down_efficiency'] ?? null);
            [$fourthDownConversions, $fourthDownAttempts] = $this->parseFraction($row['fourth_down_efficiency'] ?? null);
            [$redZoneScores, $redZoneAttempts] = $this->parseFraction($row['red_zone_scored_and_attempted'] ?? null);
            [$penalties, $penaltyYards] = $this->parseFraction($row['penalties'] ?? null);
            [$sacksAllowed] = $this->parseFraction($row['sacks_and_yards_lost'] ?? null);

            $buffer[] = [
                'team_id' => $teamId,
                'game_id' => $gameId,
                'team_type' => $teamType,
                'total_yards' => $this->nullableInt($row['total_yards'] ?? null),
                'passing_yards' => $this->nullableInt($row['passing_yards'] ?? null),
                'passing_completions' => $passingCompletions,
                'passing_attempts' => $passingAttempts,
                'passing_touchdowns' => null,
                'interceptions' => $this->nullableInt($row['interceptions_thrown'] ?? null),
                'rushing_yards' => $this->nullableInt($row['rushing_yards'] ?? null),
                'rushing_attempts' => $this->nullableInt($row['rushing_attempts'] ?? null),
                'rushing_touchdowns' => null,
                'fumbles' => null,
                'fumbles_lost' => $this->nullableInt($row['fumbles_lost'] ?? null),
                'sacks_allowed' => $sacksAllowed,
                'first_downs' => $this->nullableInt($row['first_downs'] ?? null),
                'third_down_conversions' => $thirdDownConversions,
                'third_down_attempts' => $thirdDownAttempts,
                'fourth_down_conversions' => $fourthDownConversions,
                'fourth_down_attempts' => $fourthDownAttempts,
                'red_zone_attempts' => $redZoneAttempts,
                'red_zone_scores' => $redZoneScores,
                'penalties' => $penalties,
                'penalty_yards' => $penaltyYards,
                'time_of_possession' => $this->nullableString($row['possession'] ?? null),
                'created_at' => $this->nullableString($row['created_at'] ?? null),
                'updated_at' => $this->nullableString($row['updated_at'] ?? null),
            ];

            if (count($buffer) >= $chunkSize) {
                TeamStat::query()->upsert($buffer, ['team_id', 'game_id'], [
                    'team_type',
                    'total_yards',
                    'passing_yards',
                    'passing_completions',
                    'passing_attempts',
                    'passing_touchdowns',
                    'interceptions',
                    'rushing_yards',
                    'rushing_attempts',
                    'rushing_touchdowns',
                    'fumbles',
                    'fumbles_lost',
                    'sacks_allowed',
                    'first_downs',
                    'third_down_conversions',
                    'third_down_attempts',
                    'fourth_down_conversions',
                    'fourth_down_attempts',
                    'red_zone_attempts',
                    'red_zone_scores',
                    'penalties',
                    'penalty_yards',
                    'time_of_possession',
                    'created_at',
                    'updated_at',
                ]);
                $upserted += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            TeamStat::query()->upsert($buffer, ['team_id', 'game_id'], [
                'team_type',
                'total_yards',
                'passing_yards',
                'passing_completions',
                'passing_attempts',
                'passing_touchdowns',
                'interceptions',
                'rushing_yards',
                'rushing_attempts',
                'rushing_touchdowns',
                'fumbles',
                'fumbles_lost',
                'sacks_allowed',
                'first_downs',
                'third_down_conversions',
                'third_down_attempts',
                'fourth_down_conversions',
                'fourth_down_attempts',
                'red_zone_attempts',
                'red_zone_scores',
                'penalties',
                'penalty_yards',
                'time_of_possession',
                'created_at',
                'updated_at',
            ]);
            $upserted += count($buffer);
        }

        return ['upserted' => $upserted, 'skipped' => $skipped];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $gameLookup
     * @param  array<int, true>  $teamIdLookup
     * @param  array<string, int>  $playerIdLookup
     * @return array{upserted: int, skipped: int}
     */
    protected function importPlayerStats(
        array $rows,
        array $gameLookup,
        array $teamIdLookup,
        array $playerIdLookup,
        int $chunkSize
    ): array {
        $buffer = [];
        $upserted = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $gameKey = strtoupper((string) ($row['game_id'] ?? ''));
            $gameId = $gameLookup[$gameKey] ?? null;
            $teamId = isset($row['team_id']) ? (int) $row['team_id'] : null;
            $playerEspnId = isset($row['player_id']) ? trim((string) $row['player_id']) : '';

            if (! $gameId || $teamId === null || ! isset($teamIdLookup[$teamId]) || $playerEspnId === '') {
                $skipped++;
                continue;
            }

            $playerId = $playerIdLookup[$playerEspnId] ?? null;
            if (! $playerId) {
                $player = Player::query()->firstOrCreate(
                    ['espn_id' => $playerEspnId],
                    [
                        'team_id' => $teamId,
                        'full_name' => $this->nullableString($row['long_name'] ?? null),
                        'first_name' => $this->splitName((string) ($row['long_name'] ?? ''))[0],
                        'last_name' => $this->splitName((string) ($row['long_name'] ?? ''))[1],
                    ]
                );
                $playerId = (int) $player->id;
                $playerIdLookup[$playerEspnId] = $playerId;
            }

            $passing = $this->decodeStatObject($row['passing'] ?? null);
            $rushing = $this->decodeStatObject($row['rushing'] ?? null);
            $receiving = $this->decodeStatObject($row['receiving'] ?? null);
            $defense = $this->decodeStatObject($row['defense'] ?? null);
            $kicking = $this->decodeStatObject($row['kicking'] ?? null);

            $buffer[] = [
                'player_id' => $playerId,
                'game_id' => $gameId,
                'team_id' => $teamId,
                'passing_completions' => $this->nullableInt($passing['completions'] ?? $passing['passCompletions'] ?? null),
                'passing_attempts' => $this->nullableInt($passing['attempts'] ?? $passing['passAttempts'] ?? null),
                'passing_yards' => $this->nullableInt($passing['yards'] ?? $passing['passYds'] ?? $passing['passingYards'] ?? null),
                'passing_touchdowns' => $this->nullableInt($passing['td'] ?? $passing['passTD'] ?? null),
                'interceptions_thrown' => $this->nullableInt($passing['interceptions'] ?? $passing['ints'] ?? null),
                'sacks_taken' => $this->nullableInt($passing['sacks'] ?? $passing['sacksTaken'] ?? null),
                'rushing_attempts' => $this->nullableInt($rushing['carries'] ?? $rushing['attempts'] ?? null),
                'rushing_yards' => $this->nullableInt($rushing['rushYds'] ?? $rushing['yards'] ?? $rushing['rushingYards'] ?? null),
                'rushing_touchdowns' => $this->nullableInt($rushing['rushTD'] ?? $rushing['td'] ?? null),
                'rushing_long' => $this->nullableInt($rushing['longRush'] ?? $rushing['long'] ?? null),
                'receptions' => $this->nullableInt($receiving['receptions'] ?? $receiving['rec'] ?? null),
                'receiving_yards' => $this->nullableInt($receiving['receivingYards'] ?? $receiving['recYds'] ?? $receiving['yards'] ?? null),
                'receiving_touchdowns' => $this->nullableInt($receiving['receivingTD'] ?? $receiving['td'] ?? null),
                'receiving_targets' => $this->nullableInt($receiving['targets'] ?? null),
                'receiving_long' => $this->nullableInt($receiving['longReception'] ?? $receiving['long'] ?? null),
                'tackles_total' => $this->nullableInt($defense['totalTackles'] ?? null),
                'tackles_solo' => $this->nullableInt($defense['soloTackles'] ?? null),
                'tackles_assists' => $this->nullableInt($defense['assistedTackles'] ?? $defense['assistTackles'] ?? null),
                'sacks' => $this->nullableFloat($defense['sacks'] ?? null),
                'interceptions' => $this->nullableInt($defense['defensiveInterceptions'] ?? $defense['interceptions'] ?? null),
                'passes_defended' => $this->nullableInt($defense['passDeflections'] ?? null),
                'fumbles_forced' => $this->nullableInt($defense['forcedFumbles'] ?? null),
                'fumbles_recovered' => $this->nullableInt($defense['fumblesRecovered'] ?? null),
                'field_goals_made' => $this->nullableInt($kicking['fieldGoalsMade'] ?? null),
                'field_goals_attempted' => $this->nullableInt($kicking['fieldGoalsAttempted'] ?? null),
                'extra_points_made' => $this->nullableInt($kicking['extraPointsMade'] ?? null),
                'extra_points_attempted' => $this->nullableInt($kicking['extraPointsAttempted'] ?? null),
                'created_at' => $this->nullableString($row['created_at'] ?? null),
                'updated_at' => $this->nullableString($row['updated_at'] ?? null),
            ];

            if (count($buffer) >= $chunkSize) {
                PlayerStat::query()->upsert($buffer, ['player_id', 'game_id'], [
                    'team_id',
                    'passing_completions',
                    'passing_attempts',
                    'passing_yards',
                    'passing_touchdowns',
                    'interceptions_thrown',
                    'sacks_taken',
                    'rushing_attempts',
                    'rushing_yards',
                    'rushing_touchdowns',
                    'rushing_long',
                    'receptions',
                    'receiving_yards',
                    'receiving_touchdowns',
                    'receiving_targets',
                    'receiving_long',
                    'tackles_total',
                    'tackles_solo',
                    'tackles_assists',
                    'sacks',
                    'interceptions',
                    'passes_defended',
                    'fumbles_forced',
                    'fumbles_recovered',
                    'field_goals_made',
                    'field_goals_attempted',
                    'extra_points_made',
                    'extra_points_attempted',
                    'created_at',
                    'updated_at',
                ]);
                $upserted += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            PlayerStat::query()->upsert($buffer, ['player_id', 'game_id'], [
                'team_id',
                'passing_completions',
                'passing_attempts',
                'passing_yards',
                'passing_touchdowns',
                'interceptions_thrown',
                'sacks_taken',
                'rushing_attempts',
                'rushing_yards',
                'rushing_touchdowns',
                'rushing_long',
                'receptions',
                'receiving_yards',
                'receiving_touchdowns',
                'receiving_targets',
                'receiving_long',
                'tackles_total',
                'tackles_solo',
                'tackles_assists',
                'sacks',
                'interceptions',
                'passes_defended',
                'fumbles_forced',
                'fumbles_recovered',
                'field_goals_made',
                'field_goals_attempted',
                'extra_points_made',
                'extra_points_attempted',
                'created_at',
                'updated_at',
            ]);
            $upserted += count($buffer);
        }

        return ['upserted' => $upserted, 'skipped' => $skipped];
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    protected function parseFraction(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [null, null];
        }

        $separator = str_contains($value, '/') ? '/' : '-';
        $parts = explode($separator, $value);
        if (count($parts) !== 2) {
            return [null, null];
        }

        return [$this->nullableInt($parts[0]), $this->nullableInt($parts[1])];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeStatObject(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded)) {
            $decodedTwice = json_decode($decoded, true);

            return is_array($decodedTwice) ? $decodedTwice : [];
        }

        return [];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function splitName(string $fullName): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return [$first !== '' ? $first : null, $last !== '' ? $last : null];
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $clean = preg_replace('/[^0-9\-]/', '', (string) $value);

        return ($clean === '' || $clean === '-') ? null : (int) $clean;
    }

    protected function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
        }

        return false;
    }
}
