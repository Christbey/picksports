<?php

namespace App\Console\Commands\NFL;

use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMatchupTrackingCommand extends Command
{
    protected $signature = 'nfl:sync-matchup-tracking {--season= : Required season}';

    protected $description = 'Import free NGS release times and PFR missed tackles via nflverse for independent matchup analysis';

    public function handle(ProviderSourceStorage $sources): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2018 || $season > (int) now()->year) {
            $this->error('Provide an explicit valid season from 2018 onward.');

            return self::FAILURE;
        }
        $paths = [];
        try {
            $games = DB::table('nflverse_pbp_plays')->where('season', $season)->whereNotNull('nfl_game_id')->whereNotNull('possession_team_id')
                ->select(['nflverse_game_id', 'nfl_game_id', 'week', 'possession_team', 'possession_team_id', 'defense_team'])->distinct()->get();
            $byGame = $games->groupBy(fn ($r) => $r->nflverse_game_id.'|'.$r->possession_team);
            $byWeek = $games->groupBy(fn ($r) => $r->week.'|'.$r->possession_team);
            $updates = [];
            $skipped = 0;
            $seen = [];
            foreach (['release_time' => 'https://github.com/nflverse/nflverse-data/releases/download/nextgen_stats/ngs_passing.csv.gz',
                'missed_tackles' => "https://github.com/nflverse/nflverse-data/releases/download/pfr_advstats/advstats_week_def_{$season}.csv"] as $metric => $url) {
                $path = tempnam(sys_get_temp_dir(), 'nfl-tracking-');
                $paths[] = $path;
                $body = Http::connectTimeout(15)->timeout(120)->retry(2, 1000)->get($url)->throw()->body();
                file_put_contents($path, $body);
                $csv = $metric === 'release_time' ? gzdecode($body) : $body;
                if (! is_string($csv)) {
                    throw new RuntimeException('Invalid compressed tracking source; no samples updated.');
                }
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $csv);
                rewind($stream);
                $header = fgetcsv($stream, escape: '');
                $required = $metric === 'release_time'
                    ? ['season', 'season_type', 'week', 'team_abbr', 'player_gsis_id', 'player_position', 'avg_time_to_throw', 'attempts']
                    : ['season', 'game_type', 'game_id', 'team', 'opponent', 'pfr_player_id', 'def_tackles_combined', 'def_missed_tackles'];
                if (! is_array($header) || count(array_unique($header)) !== count($header) || array_diff($required, $header)) {
                    throw new RuntimeException('Invalid tracking header; no samples updated.');
                }
                $sourceCount = 0;
                while (($values = fgetcsv($stream, escape: '')) !== false) {
                    if (count($values) !== count($header)) {
                        throw new RuntimeException('Malformed tracking row; no samples updated.');
                    }
                    $row = array_combine($header, $values);
                    if ((int) $row['season'] !== $season || ($row['season_type'] ?? $row['game_type']) !== 'REG') {
                        continue;
                    }
                    if ($metric === 'release_time') {
                        if ($row['week'] === '0' || $row['player_position'] !== 'QB') {
                            continue;
                        }
                        if (! ctype_digit($row['week']) || ! preg_match('/^00-\d{7}$/', $row['player_gsis_id'])) {
                            throw new RuntimeException('Invalid weekly QB tracking identity; no samples updated.');
                        }
                        $key = $row['week'].'|'.$this->team($row['team_abbr']);
                        $matches = $byWeek->get($key);
                        $player = 'gsis:'.$row['player_gsis_id'];
                        $value = $this->number($row['avg_time_to_throw'], 0.1, 15);
                        $size = $this->number($row['attempts'], 1, 100, true);
                    } else {
                        if (! str_starts_with($row['game_id'], $season.'_') || ! filled($row['pfr_player_id'])) {
                            throw new RuntimeException('Invalid defensive tracking identity; no samples updated.');
                        }
                        $key = $row['game_id'].'|'.$this->team($row['team']);
                        $matches = $byGame->get($key);
                        $player = 'pfr:'.$row['pfr_player_id'];
                        $value = $this->number($row['def_missed_tackles'], 0, 100, true);
                        $tackles = $this->number($row['def_tackles_combined'], 0, 100, true);
                        $size = $value !== null && $tackles !== null ? $value + $tackles : null;
                    }
                    $identity = $metric.'|'.$key.'|'.$player;
                    if (isset($seen[$identity])) {
                        throw new RuntimeException('Duplicate tracking identity; no samples updated.');
                    }
                    $seen[$identity] = true;
                    if ($matches === null) {
                        $skipped++;

                        continue;
                    }
                    if ($matches->count() !== 1 || ($metric === 'missed_tackles' && $matches->first()->defense_team !== $this->team($row['opponent']))) {
                        throw new RuntimeException('Ambiguous tracking game or opponent mismatch; no samples updated.');
                    }
                    $game = $matches->first();
                    $updates[] = ['game_id' => $game->nfl_game_id, 'team_id' => $game->possession_team_id, 'season' => $season,
                        'metric' => $metric, 'player_id' => $player, 'value' => $value, 'sample_size' => $size, 'observed_at' => now()->toDateTimeString()];
                    $sourceCount++;
                }
                fclose($stream);
                if ($sourceCount === 0) {
                    throw new RuntimeException('No verified samples for '.$metric.'; no samples updated.');
                }
                $sources->archive('nflverse', $metric === 'release_time' ? 'ngs-passing' : 'pfr-defensive-tackles', $path, ['source_url' => $url, 'season' => $season]);
                $this->line($metric.': '.$sourceCount.' verified samples.');
            }
            DB::transaction(function () use ($season, $updates): void {
                DB::table('nfl_matchup_tracking_samples')->where('season', $season)->delete();
                foreach (array_chunk($updates, 500) as $chunk) {
                    DB::table('nfl_matchup_tracking_samples')->insert($chunk);
                }
            });
            $this->info('Unresolved source game rows: '.$skipped.'.');

            return $skipped > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    }

    private function number(string $raw, float $min, float $max, bool $integer = false): int|float|null
    {
        if (in_array(trim($raw), ['', 'NA'], true)) {
            return null;
        }
        $value = filter_var($raw, $integer ? FILTER_VALIDATE_INT : FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < $min || $value > $max) {
            throw new RuntimeException('Invalid tracking measurement; no samples updated.');
        }

        return $value;
    }

    private function team(string $team): string
    {
        return ['LA' => 'LAR', 'WAS' => 'WSH', 'JAC' => 'JAX', 'ARZ' => 'ARI'][$team] ?? $team;
    }
}
