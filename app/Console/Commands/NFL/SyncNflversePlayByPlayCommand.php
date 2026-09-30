<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncNflversePlayByPlayCommand extends Command
{
    protected $signature = 'nfl:sync-nflverse-pbp {--season= : Required season}';

    protected $description = 'Refresh public nflverse play-by-play and verify completed-game coverage for matchup analysis';

    public function handle(ProviderSourceStorage $sources): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 1999 || $season > (int) now()->year) {
            $this->error('Provide an explicit valid --season.');

            return self::FAILURE;
        }
        $path = tempnam(sys_get_temp_dir(), 'nfl-pbp-');
        $compressed = $path.'.csv.gz';
        try {
            $url = "https://github.com/nflverse/nflverse-data/releases/download/pbp/play_by_play_{$season}.csv.gz";
            $body = Http::timeout(120)->retry(2, 1000)->get($url)->throw()->body();
            file_put_contents($compressed, $body);
            $stream = gzopen($compressed, 'rb');
            $header = $stream ? fgetcsv($stream, escape: '') : false;
            $required = ['game_id', 'play_id', 'season', 'week', 'home_team', 'away_team', 'posteam', 'defteam', 'play_type', 'epa', 'yards_gained'];
            if (! is_array($header) || array_diff($required, $header)) {
                throw new RuntimeException('Invalid play-by-play source header; no rows imported.');
            }
            $count = 0;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Malformed play-by-play row; no rows imported.');
                }
                $row = array_combine($header, $values);
                if ((int) $row['season'] !== $season || trim($row['game_id']) === '' || trim($row['play_id']) === '') {
                    throw new RuntimeException('Source season or play identity mismatch; no rows imported.');
                }
                $count++;
            }
            gzclose($stream);
            if ($count === 0) {
                throw new RuntimeException('Empty play-by-play source; no rows imported.');
            }
            // Verify the recoverable source before updating provider rows.
            $sources->archive('nflverse', 'pbp', $compressed, ['source_url' => $url, 'season' => $season]);
            $exit = $this->call('nfl:import-nflverse-layer', ['dataset' => 'pbp', 'file' => $compressed,
                '--from-season' => $season, '--to-season' => $season, '--archive-source' => true]);
            if ($exit !== self::SUCCESS) {
                return $exit;
            }
            $games = Game::query()->where('season', $season)->whereIn('season_type', ['2', 'regular', 'REG'])
                ->where('status', 'STATUS_FINAL')->whereDate('game_date', '<', now()->utc()->toDateString())
                ->get(['id', 'home_team_id', 'away_team_id']);
            $plays = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->pluck('id'))
                ->whereIn('play_type', ['pass', 'run'])->whereNotNull('epa')
                ->selectRaw('nfl_game_id, possession_team_id, COUNT(*) AS plays')
                ->groupBy('nfl_game_id', 'possession_team_id')->get()
                ->keyBy(fn ($row) => $row->nfl_game_id.':'.$row->possession_team_id);
            $missing = $games->filter(fn ($game) => ($plays->get($game->id.':'.$game->home_team_id)?->plays ?? 0) < 30
                || ($plays->get($game->id.':'.$game->away_team_id)?->plays ?? 0) < 30)->pluck('id');
            $this->info(sprintf('Completed-game play coverage: %d/%d.', $games->count() - $missing->count(), $games->count()));
            if ($missing->isNotEmpty()) {
                $this->error('Missing or incomplete provider plays for game IDs: '.$missing->implode(', '));

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (isset($stream) && is_resource($stream)) {
                gzclose($stream);
            }
            @unlink($compressed);
            @unlink($path);
        }
    }
}
