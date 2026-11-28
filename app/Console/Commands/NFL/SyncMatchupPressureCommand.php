<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\MatchupSamplePublication;
use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncMatchupPressureCommand extends Command
{
    protected $signature = 'nfl:sync-matchup-pressure {--season= : Required season}';

    protected $description = 'Join free PFR weekly pressure counts from nflverse to verified game and player identities';

    public function handle(ProviderSourceStorage $sources, MatchupSamplePublication $publication): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2018 || $season > (int) now()->year) {
            $this->error('Provide an explicit valid season from 2018 onward.');

            return self::FAILURE;
        }
        $connection = DB::connection();
        $path = tempnam(sys_get_temp_dir(), 'nfl-pressure-');
        $stream = null;
        try {
            $url = "https://github.com/nflverse/nflverse-data/releases/download/pfr_advstats/advstats_week_pass_{$season}.csv";
            $snapshot = $publication->snapshot($connection, $season, true);
            [$raw] = $publication->download($url);
            if (file_put_contents($path, $raw) !== strlen($raw)) {
                throw new RuntimeException('Unable to stage pressure source.');
            }
            $stream = fopen($path, 'r');
            $header = fgetcsv($stream, escape: '');
            if (! is_array($header) || count(array_unique($header)) !== count($header)
                || array_diff(['game_id', 'season', 'week', 'game_type', 'team', 'opponent', 'pfr_player_id', 'times_pressured', 'times_sacked', 'times_pressured_pct'], $header)) {
                throw new RuntimeException('Invalid pressure header; no rows updated.');
            }
            $games = $snapshot['plays']->groupBy(fn ($row) => $row->nflverse_game_id.'|'.$row->possession_team);
            $rosters = $snapshot['rosters']->groupBy(fn ($row) => $row->team_id.'|'.$row->pfr_id);
            $updates = [];
            $seen = [];
            $skipped = 0;
            $rowsRead = 0;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                $publication->assertRowBudget(++$rowsRead);
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Malformed pressure row; no rows updated.');
                }
                $row = array_combine($header, $values);
                $key = $row['game_id'].'|'.$row['pfr_player_id'];
                if ((int) $row['season'] !== $season || ! str_starts_with($row['game_id'], $season.'_') || ! filled($row['pfr_player_id']) || isset($seen[$key])) {
                    throw new RuntimeException('Wrong season or duplicate/empty pressure identity; no rows updated.');
                }
                $seen[$key] = true;
                $pressures = $this->countValue($row['times_pressured']);
                $sacks = $this->countValue($row['times_sacked']);
                $rate = in_array(trim($row['times_pressured_pct']), ['', 'NA'], true) ? null : filter_var($row['times_pressured_pct'], FILTER_VALIDATE_FLOAT);
                if ($rate === false || ($rate !== null && ($rate < 0 || $rate > 1)) || ($sacks !== null && $pressures !== null && $sacks > $pressures)) {
                    throw new RuntimeException('Invalid pressure counts/rate; no rows updated.');
                }
                if ($row['game_type'] !== 'REG') {
                    continue;
                }
                $matches = $games->get($row['game_id'].'|'.$this->team($row['team']));
                if ($matches === null) {
                    $skipped++;

                    continue;
                }
                if ($matches->count() !== 1 || $matches->first()->defense_team !== $this->team($row['opponent'])) {
                    throw new RuntimeException('Ambiguous game or opponent mismatch; no rows updated.');
                }
                $game = $matches->first();
                $ids = $rosters->get($game->possession_team_id.'|'.$row['pfr_player_id'], collect())->pluck('gsis_id')->unique()->values();
                if ($ids->count() !== 1 || ! filled($ids->first())) {
                    $skipped++;

                    continue;
                }
                $updates[] = ['game_id' => $game->nfl_game_id, 'team_id' => $game->possession_team_id, 'opponent_id' => $game->defense_team_id,
                    'season' => $season, 'gsis_id' => $ids->first(), 'pfr_id' => $row['pfr_player_id'], 'pressures' => $pressures,
                    'sacks' => $sacks, 'pressure_rate' => $rate, 'observed_at' => now()->toDateTimeString()];
                $publication->assertSampleBudget(count($updates));
            }
            if ($updates === []) {
                throw new RuntimeException('No verified pressure samples; no rows updated.');
            }
            if (count(array_unique(array_map(fn ($row) => $row['game_id'].'|'.$row['team_id'].'|'.$row['gsis_id'], $updates))) !== count($updates)) {
                throw new RuntimeException('Conflicting player mappings; no rows updated.');
            }
            $sources->archive('nflverse', 'pfr-weekly-pressure', $path, ['source_url' => $url, 'season' => $season]);
            if ($skipped > 0) {
                throw new RuntimeException('Unresolved pressure source identities; no samples updated.');
            }
            $publication->publish($connection, 'nfl_matchup_pressure_samples', $season, $updates, $snapshot);
            $this->info(sprintf('Pressure samples joined: %d; unresolved game/player rows: %d.', count($updates), $skipped));

            return $skipped > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($path);
        }
    }

    private function countValue(string $value): ?int
    {
        if (in_array(trim($value), ['', 'NA'], true)) {
            return null;
        }
        if (! ctype_digit($value) || (int) $value > 100) {
            throw new RuntimeException('Invalid pressure count; no rows updated.');
        }

        return (int) $value;
    }

    private function team(string $value): string
    {
        return ['LA' => 'LAR', 'WAS' => 'WSH', 'JAC' => 'JAX', 'ARZ' => 'ARI', 'SD' => 'LAC', 'OAK' => 'LV', 'STL' => 'LAR'][$value] ?? $value;
    }
}
