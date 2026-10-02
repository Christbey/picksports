<?php

namespace App\Console\Commands\NFL;

use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMatchupChartingCommand extends Command
{
    protected $signature = 'nfl:sync-matchup-charting {--season= : Required season}';

    protected $description = 'Join public FTN charting to verified NFL play identities for independent matchup analysis';

    public function handle(ProviderSourceStorage $sources): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2022 || $season > (int) now()->year) {
            $this->error('Provide an explicit valid --season (2022 or later).');

            return self::FAILURE;
        }
        $path = tempnam(sys_get_temp_dir(), 'nfl-charting-');
        $stream = null;
        try {
            $url = "https://github.com/nflverse/nflverse-data/releases/download/ftn_charting/ftn_charting_{$season}.csv";
            file_put_contents($path, Http::timeout(120)->retry(2, 1000)->get($url)->throw()->body());
            $stream = fopen($path, 'r');
            $header = fgetcsv($stream, escape: '');
            $flags = ['is_play_action', 'is_screen_pass', 'is_rpo', 'is_motion'];
            $counts = ['n_defense_box', 'n_blitzers', 'n_pass_rushers'];
            if (! is_array($header) || array_diff(['season', 'nflverse_game_id', 'nflverse_play_id', 'read_thrown', ...$flags, ...$counts], $header)) {
                throw new RuntimeException('Invalid charting header; no rows updated.');
            }
            $plays = DB::table('nflverse_pbp_plays')->where('season', $season)->whereNotNull('nfl_game_id')
                ->get(['id', 'nflverse_play_key', 'nflverse_game_id', 'play_id'])->groupBy(fn ($row) => $row->nflverse_game_id.'|'.$row->play_id);
            $updates = [];
            $unmatched = 0;
            $seen = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Malformed charting row; no rows updated.');
                }
                $row = array_combine($header, $values);
                $key = $row['nflverse_game_id'].'|'.$row['nflverse_play_id'];
                if ((int) $row['season'] !== $season || isset($seen[$key])) {
                    throw new RuntimeException('Duplicate play identity or wrong season; no rows updated.');
                }
                $seen[$key] = true;
                $read = strtoupper(trim($row['read_thrown']));
                $mapped = ['ftn_read_thrown' => match ($read) {
                    '0', '1', '2', 'CHK', 'DES', 'SD' => $read,
                    '', 'NA' => null,
                    default => throw new RuntimeException('Invalid charted read; no rows updated.'),
                }];
                foreach ($flags as $field) {
                    $value = strtoupper(trim($row[$field]));
                    $mapped['ftn_'.$field] = match ($value) {
                        'TRUE', '1' => true, 'FALSE', '0' => false, '', 'NA' => null,
                        default => throw new RuntimeException('Invalid charted flag; no rows updated.'),
                    };
                }
                foreach ($counts as $field) {
                    $value = trim($row[$field]);
                    if ($value !== '' && $value !== 'NA' && (! ctype_digit($value) || (int) $value > 11)) {
                        throw new RuntimeException('Invalid charted player count; no rows updated.');
                    }
                    $mapped['ftn_'.$field] = ctype_digit($value) ? (int) $value : null;
                }
                $matches = $plays->get($key);
                if ($matches === null || $matches->count() !== 1) {
                    $unmatched++;

                    continue;
                }
                $play = $matches->first();
                $updates[] = ['id' => $play->id, 'nflverse_play_key' => $play->nflverse_play_key,
                    ...$mapped, 'ftn_observed_at' => now()];
            }
            if ($updates === []) {
                throw new RuntimeException('No verified play matches; no rows updated.');
            }
            $sources->archive('nflverse', 'ftn-charting', $path, ['source_url' => $url, 'season' => $season]);
            DB::transaction(function () use ($updates): void {
                foreach (array_chunk($updates, 500) as $chunk) {
                    DB::table('nflverse_pbp_plays')->upsert($chunk, ['id'], array_diff(array_keys($chunk[0]), ['id', 'nflverse_play_key']));
                }
            });
            $this->info(sprintf('Charting joined: %d plays; unmatched: %d.', count($updates), $unmatched));
            $eligible = DB::table('nflverse_pbp_plays')->where('season', $season)->whereNotNull('nfl_game_id')->whereIn('play_type', ['pass', 'run']);
            $total = (clone $eligible)->count();
            $covered = (clone $eligible)->whereNotNull('ftn_observed_at')->count();
            $this->info("Charted scrimmage coverage: {$covered}/{$total}.");
            $incompleteGames = (clone $eligible)->selectRaw('nfl_game_id, COUNT(*) AS total, COUNT(ftn_observed_at) AS charted')
                ->groupBy('nfl_game_id')->get()->filter(fn ($row) => $row->charted < $row->total * .9)->pluck('nfl_game_id');
            if ($unmatched > 0 || $incompleteGames->isNotEmpty()) {
                $this->line('Incomplete game IDs: '.$incompleteGames->implode(', '));
                $this->error('Charting coverage incomplete; unmatched identities and missing charting require attention.');

                return self::FAILURE;
            }

            return self::SUCCESS;
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
}
