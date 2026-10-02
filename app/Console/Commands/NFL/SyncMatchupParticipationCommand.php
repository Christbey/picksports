<?php

namespace App\Console\Commands\NFL;

use App\Services\ProviderData\ProviderSourceStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMatchupParticipationCommand extends Command
{
    protected $signature = 'nfl:sync-matchup-participation {--season= : Required completed season}';

    protected $description = 'Import postseason FTN Data via nflverse coverage charting (CC-BY-SA 4.0) using verified game/play identities';

    public function handle(ProviderSourceStorage $sources): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2023 || $season >= (int) now()->year) {
            $this->error('Provide a completed --season from 2023 onward. Participation is published after the postseason.');

            return self::FAILURE;
        }
        $path = tempnam(sys_get_temp_dir(), 'nfl-participation-');
        $stream = null;
        try {
            $url = "https://github.com/nflverse/nflverse-data/releases/download/pbp_participation/pbp_participation_{$season}.csv";
            file_put_contents($path, Http::timeout(120)->retry(2, 1000)->get($url)->throw()->body());
            $stream = fopen($path, 'r');
            $header = fgetcsv($stream, escape: '');
            if (! is_array($header) || count(array_unique($header)) !== count($header)
                || array_diff(['nflverse_game_id', 'play_id', 'possession_team', 'defense_man_zone_type', 'defense_coverage_type'], $header)) {
                throw new RuntimeException('Invalid participation header; no rows updated.');
            }
            $plays = DB::table('nflverse_pbp_plays')->where('season', $season)->whereNotNull('nfl_game_id')
                ->get(['id', 'nflverse_play_key', 'nflverse_game_id', 'play_id', 'possession_team'])
                ->groupBy(fn ($row) => $row->nflverse_game_id.'|'.$row->play_id);
            $observedAt = now()->toDateTimeString();
            $updates = [];
            $seen = [];
            $unmatched = 0;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Malformed participation row; no rows updated.');
                }
                $row = array_combine($header, $values);
                $key = $row['nflverse_game_id'].'|'.$row['play_id'];
                if (! str_starts_with($row['nflverse_game_id'], $season.'_') || ! ctype_digit($row['play_id']) || isset($seen[$key])) {
                    throw new RuntimeException('Duplicate or invalid play identity/season; no rows updated.');
                }
                $seen[$key] = true;
                $zone = $this->code($row['defense_man_zone_type'], ['MAN_COVERAGE', 'ZONE_COVERAGE']);
                $coverage = $this->code($row['defense_coverage_type'], ['COVER_0', 'COVER_1', 'COVER_2', '2_MAN', 'COVER_3', 'COVER_4', 'COVER_6', 'COVER_9', 'COMBO', 'BLOWN']);
                $matches = $plays->get($key);
                if ($matches === null) {
                    $unmatched++;

                    continue;
                }
                if ($matches->count() !== 1) {
                    throw new RuntimeException('Ambiguous local play identity; no rows updated.');
                }
                $play = $matches->first();
                if (($zone !== null || $coverage !== null) && (! filled($play->possession_team) || $play->possession_team !== match ($row['possession_team']) {
                    'WAS' => 'WSH', 'LA' => 'LAR', default => $row['possession_team']
                })) {
                    throw new RuntimeException('Possession team mismatch; no rows updated.');
                }
                $updates[] = ['id' => $play->id, 'nflverse_play_key' => $play->nflverse_play_key,
                    'participation_man_zone' => $zone, 'participation_coverage' => $coverage, 'participation_observed_at' => $observedAt];
            }
            if ($updates === []) {
                throw new RuntimeException('No verified play matches; no rows updated.');
            }
            unset($plays, $seen);
            $sources->archive('nflverse', 'ftn-participation', $path, ['source_url' => $url, 'season' => $season,
                'attribution' => 'FTN Data via nflverse', 'license' => 'CC-BY-SA 4.0']);
            DB::transaction(function () use ($updates): void {
                foreach (array_chunk($updates, 500) as $chunk) {
                    DB::table('nflverse_pbp_plays')->upsert($chunk, ['id'], ['participation_man_zone', 'participation_coverage', 'participation_observed_at']);
                }
            });
            $this->info(sprintf('Participation joined: %d plays; source rows outside local play history: %d.', count($updates), $unmatched));
            $passes = DB::table('nflverse_pbp_plays')->where('season', $season)->whereNotNull('nfl_game_id')->where('play_type', 'pass');
            $total = (clone $passes)->count();
            $covered = (clone $passes)->whereNotNull('participation_man_zone')->whereNotNull('participation_coverage')->count();
            $this->info("Classified passing coverage: {$covered}/{$total}. Matchup evaluation retains per-game sample gates.");

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

    private function code(string $value, array $allowed): ?string
    {
        $value = strtoupper(trim($value));
        if (in_array($value, ['', 'NA'], true)) {
            return null;
        }
        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException('Unknown participation coverage code; no rows updated.');
        }

        return $value;
    }
}
