<?php

namespace App\Services\NFL;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Complete season projections only; source acquisition happens outside the write transaction. */
class MatchupSamplePublication
{
    public const RAW_BYTES = 33_554_432;

    public const DECODED_BYTES = 134_217_728;

    public const SOURCE_ROWS = 250_000;

    public const SAMPLES = 100_000;

    public function download(string $url, bool $gzip = false): array
    {
        $response = Http::connectTimeout(15)->timeout(120)->retry(2, 1000)
            ->withOptions(['decode_content' => false, 'progress' => static function ($total, $downloaded): void {
                if ($total > self::RAW_BYTES || $downloaded > self::RAW_BYTES) {
                    throw new RuntimeException('Tracking source exceeds the raw byte budget.');
                }
            }])->get($url)->throw();
        if ((int) $response->header('Content-Length') > self::RAW_BYTES) {
            throw new RuntimeException('Tracking source exceeds the raw byte budget.');
        }
        $raw = $response->body();
        if (strlen($raw) > self::RAW_BYTES) {
            throw new RuntimeException('Tracking source exceeds the raw byte budget.');
        }
        // A hard output limit prevents an unbounded gzip expansion; never admit truncated output.
        $decoded = $gzip ? @gzdecode($raw, self::DECODED_BYTES + 1) : $raw;
        if (! is_string($decoded) || strlen($decoded) > self::DECODED_BYTES) {
            throw new RuntimeException('Tracking source is invalid or exceeds the decoded byte budget.');
        }

        return [$raw, $decoded];
    }

    public function assertRowBudget(int $rows): void
    {
        if ($rows > self::SOURCE_ROWS) {
            throw new RuntimeException('Tracking source exceeds the row budget.');
        }
    }

    public function assertSampleBudget(int $samples): void
    {
        if ($samples > self::SAMPLES) {
            throw new RuntimeException('Tracking projection exceeds the sample budget.');
        }
    }

    public function snapshot(Connection $connection, int $season, bool $rosters, bool $locking = false, array $lockedGameIds = []): array
    {
        $query = $connection->table('nflverse_pbp_plays')->where('season', $season)->whereNotNull('nfl_game_id');
        $columns = ['nflverse_game_id', 'nfl_game_id', 'week', 'possession_team', 'possession_team_id', 'defense_team', 'defense_team_id'];
        foreach ($columns as $column) {
            $query->orderBy($column);
        }
        $plays = $query->select($columns)->distinct()->when($locking, fn ($q) => $q->lockForUpdate())->limit(self::SOURCE_ROWS + 1)->get();
        $this->assertRowBudget($plays->count());
        $ids = $plays->pluck('nfl_game_id')->unique()->sort()->values()->all();
        if (count($ids) > 2000) {
            throw new RuntimeException('Tracking source exceeds the game identity budget.');
        }
        if ($locking && array_diff($ids, $lockedGameIds) !== []) {
            throw new RuntimeException('Tracking game set changed; no samples updated.');
        }
        $games = $connection->table('nfl_games')->whereIn('id', $ids)->orderBy('id')
            ->when($locking, fn ($q) => $q->lockForUpdate())->get(['id', 'season', 'home_team_id', 'away_team_id', 'espn_event_id', 'nflverse_game_id',
                'week', 'game_date', 'game_time', 'season_type']);
        $players = collect();
        if ($rosters) {
            $players = $connection->table('nflverse_rosters')->where('season', $season)->whereNotNull('pfr_id')->whereNotNull('gsis_id')
                ->select(['team_id', 'pfr_id', 'gsis_id'])->distinct()->orderBy('team_id')->orderBy('pfr_id')->orderBy('gsis_id')
                ->when($locking, fn ($q) => $q->lockForUpdate())->limit(self::SOURCE_ROWS + 1)->get();
            $this->assertRowBudget($players->count());
        }

        return ['plays' => $plays, 'games' => $games, 'rosters' => $players];
    }

    public function publish(Connection $connection, string $table, int $season, array $updates, array $expected): void
    {
        if (! in_array($table, ['nfl_matchup_tracking_samples', 'nfl_matchup_pressure_samples'], true)
            || $updates === [] || count($updates) > self::SAMPLES) {
            throw new RuntimeException('Invalid tracking publication or sample budget.');
        }
        $connection->transaction(function () use ($connection, $table, $season, $updates, $expected): void {
            // Shared season game locks serialize competing replacements before source/destination reads.
            $lockedGames = $connection->table('nfl_games')->where(function ($query) use ($season, $expected): void {
                $query->where('season', $season)->orWhereIn('id', $expected['games']->pluck('id')->all());
            })->orderBy('id')->lockForUpdate()->limit(2001)->get(['id']);
            if ($lockedGames->count() > 2000) {
                throw new RuntimeException('Tracking season exceeds the game identity budget.');
            }
            $current = $this->snapshot($connection, $season, $table === 'nfl_matchup_pressure_samples', true, $lockedGames->pluck('id')->all());
            if ($this->fingerprint($current) !== $this->fingerprint($expected)) {
                throw new RuntimeException('Tracking identity context changed; no samples updated.');
            }
            $games = $current['games']->keyBy('id');
            foreach ($updates as $update) {
                $game = $games->get($update['game_id']);
                $teams = $game === null ? [] : [(int) $game->home_team_id, (int) $game->away_team_id];
                if ($game === null || (int) $game->season !== $season || count(array_unique($teams)) !== 2 || in_array(0, $teams, true)
                    || ! in_array((int) $update['team_id'], $teams, true)
                    || (array_key_exists('opponent_id', $update) && (! in_array((int) $update['opponent_id'], $teams, true) || (int) $update['opponent_id'] === (int) $update['team_id']))) {
                    throw new RuntimeException('Tracking game participant identity is inconsistent; no samples updated.');
                }
            }
            $keys = array_fill_keys(array_map(fn (array $row): string => $this->identity($table, $row), $updates), true);
            if (count($keys) !== count($updates)) {
                throw new RuntimeException('Duplicate tracking projection identity; no samples updated.');
            }
            $prior = $connection->table($table)->where('season', $season)->orderBy('id')->lockForUpdate()->limit(self::SAMPLES + 1)->get();
            $this->assertSampleBudget($prior->count());
            foreach ($prior as $row) {
                if (! isset($keys[$this->identity($table, (array) $row)])) {
                    throw new RuntimeException('Tracking replacement omits prior identities; explicit reviewed reconciliation is required.');
                }
            }
            $connection->table($table)->where('season', $season)->delete();
            foreach (array_chunk($updates, 500) as $chunk) {
                $connection->table($table)->insert($chunk);
            }
        }, 3);
    }

    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function identity(string $table, array $row): string
    {
        return $table === 'nfl_matchup_tracking_samples'
            ? implode('|', [$row['game_id'], $row['team_id'], $row['metric'], $row['player_id']])
            : implode('|', [$row['game_id'], $row['team_id'], $row['gsis_id']]);
    }
}
