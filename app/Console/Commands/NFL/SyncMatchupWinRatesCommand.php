<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Team;
use App\Services\NFL\Matchups\NflMatchupWinRates;
use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMatchupWinRatesCommand extends Command
{
    protected $signature = 'nfl:sync-matchup-win-rates {--season= : Required season}';

    protected $description = 'Archive and import published ESPN team blocking and defensive win rates for independent matchup analysis';

    public function handle(NflMatchupWinRates $rates, ProviderSourceStorage $sources): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $url = $season ? config('nfl.matchup_win_rate_sources.'.$season) : null;
        if (! is_string($url) || ! str_starts_with($url, 'https://www.espn.com/nfl/story/')) {
            $this->error('An explicit season with a configured ESPN win-rate source is required.');

            return self::FAILURE;
        }
        $path = null;
        try {
            $html = Http::connectTimeout(15)->timeout(60)->retry(2, 1000)->get($url)->throw()->body();
            $snapshot = $rates->parse($html, $season);
            $mapped = [];
            $teams = Team::query()->get()->groupBy(fn (Team $team): string => trim($team->location.' '.$team->name));
            foreach ($snapshot['teams'] as $name => $values) {
                $matches = $teams->get($name, collect());
                if ($matches->count() !== 1) {
                    throw new RuntimeException('Unresolved or ambiguous ESPN team: '.$name);
                }
                $mapped[$matches->first()->id] = $values;
            }
            ksort($mapped);
            $snapshot['teams'] = json_encode($mapped, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $path = tempnam(sys_get_temp_dir(), 'nfl-win-rates-');
            file_put_contents($path, $html);
            $sources->archive('espn', 'nfl-team-win-rates', $path, ['source_url' => $url, 'season' => $season, 'through_week' => $snapshot['through_week']]);
            DB::table('nfl_matchup_win_rate_snapshots')->insertOrIgnore([...$snapshot, 'content_hash' => $hash, 'source_url' => $url, 'observed_at' => now()->utc()]);
            $this->info('Verified 32 teams through Week '.$snapshot['through_week'].'. Immutable snapshot retained.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
        }
    }
}
