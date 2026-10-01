<?php

namespace App\Console\Commands\NFL;

use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMatchupRostersCommand extends Command
{
    protected $signature = 'nfl:sync-matchup-rosters {--season=}';

    protected $description = 'Refresh verified NFLverse roster player-ID mappings for matchup analysis';

    public function handle(ProviderSourceStorage $sources): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2002 || $season > (int) now()->year) {
            $this->error('Provide an explicit valid --season.');

            return self::FAILURE;
        }
        $path = tempnam(sys_get_temp_dir(), 'nfl-roster-');
        $stream = null;
        try {
            $url = "https://github.com/nflverse/nflverse-data/releases/download/rosters/roster_{$season}.csv";
            file_put_contents($path, Http::timeout(60)->retry(2, 1000)->get($url)->throw()->body());
            $stream = fopen($path, 'r');
            $header = fgetcsv($stream, escape: '');
            if (! is_array($header) || array_diff(['season', 'team', 'position', 'espn_id', 'gsis_id', 'full_name'], $header)) {
                throw new RuntimeException('Roster source lacks required identity columns.');
            }
            $qbTeams = [];
            $identities = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Malformed roster source.');
                }
                $row = array_combine($header, $values);
                if ((string) $row['season'] !== (string) $season) {
                    throw new RuntimeException('Roster source contains an unexpected season.');
                }
                if ($row['position'] !== 'QB' || ! preg_match('/^\d+$/', $row['espn_id']) || ! preg_match('/^00-\d+$/', $row['gsis_id'])) {
                    continue;
                }
                if (isset($identities[$row['espn_id']]) && $identities[$row['espn_id']] !== $row['gsis_id']) {
                    throw new RuntimeException('Conflicting quarterback ID mappings.');
                }
                $identities[$row['espn_id']] = $row['gsis_id'];
                $qbTeams[$row['team']] = true;
            }
            if (count($qbTeams) !== 32) {
                throw new RuntimeException('Roster source requires quarterback ID mappings for all 32 teams.');
            }
            $sources->archive('nflverse', 'matchup-rosters', $path, ['source_url' => $url]);
            $exit = $this->call('nfl:import-nflverse-layer', ['dataset' => 'rosters', 'file' => $path,
                '--from-season' => $season, '--to-season' => $season, '--without-raw-payload' => true]);
            if ($exit === self::SUCCESS) {
                $this->info('Verified roster mappings: 32 teams; '.count($identities).' quarterback identities.');
            }

            return $exit;
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
