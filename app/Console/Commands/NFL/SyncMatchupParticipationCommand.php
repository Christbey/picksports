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

    protected $description = 'Import postseason FTN Data via nflverse pressure, personnel and coverage charting (CC-BY-SA 4.0) using verified game/play identities';

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
                || array_diff(['nflverse_game_id', 'play_id', 'possession_team', 'defense_man_zone_type', 'defense_coverage_type', 'was_pressure', 'number_of_pass_rushers', 'offense_personnel', 'defense_personnel'], $header)) {
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
                $pressure = match (strtoupper(trim($row['was_pressure']))) {
                    'TRUE', '1' => true, 'FALSE', '0' => false, '', 'NA' => null,
                    default => throw new RuntimeException('Invalid pressure flag; no rows updated.'),
                };
                $rushers = trim($row['number_of_pass_rushers']);
                if (! in_array($rushers, ['', 'NA'], true) && (! ctype_digit($rushers) || (int) $rushers > 11)) {
                    throw new RuntimeException('Invalid pass-rusher count; no rows updated.');
                }
                $rushers = ctype_digit($rushers) ? (int) $rushers : null;
                $offense = $this->personnel($row['offense_personnel']);
                $defense = $this->personnel($row['defense_personnel']);
                $package = $offense === null ? null : 'OTHER';
                if ($offense !== null && ($offense['QB'] ?? 0) === 1
                    && ($offense['C'] ?? 0) + ($offense['G'] ?? 0) + ($offense['T'] ?? 0) === 5
                    && ($offense['RB'] ?? 0) + ($offense['FB'] ?? 0) + ($offense['TE'] ?? 0) + ($offense['WR'] ?? 0) === 5) {
                    $package = (string) (($offense['RB'] ?? 0) + ($offense['FB'] ?? 0)).(string) ($offense['TE'] ?? 0);
                }
                $dbs = $defense === null ? null : ($defense['CB'] ?? 0) + ($defense['FS'] ?? 0) + ($defense['SS'] ?? 0) + ($defense['S'] ?? 0);
                $matches = $plays->get($key);
                if ($matches === null) {
                    $unmatched++;

                    continue;
                }
                if ($matches->count() !== 1) {
                    throw new RuntimeException('Ambiguous local play identity; no rows updated.');
                }
                $play = $matches->first();
                if (($zone !== null || $coverage !== null || $pressure !== null || $rushers !== null || $package !== null || $dbs !== null) && (! filled($play->possession_team) || $play->possession_team !== match ($row['possession_team']) {
                    'WAS' => 'WSH', 'LA' => 'LAR', default => $row['possession_team']
                })) {
                    throw new RuntimeException('Possession team mismatch; no rows updated.');
                }
                $updates[] = ['id' => $play->id, 'nflverse_play_key' => $play->nflverse_play_key,
                    'participation_pressure' => $pressure, 'participation_rushers' => $rushers,
                    'participation_offense_package' => $package, 'participation_defense_dbs' => $dbs,
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
                    DB::table('nflverse_pbp_plays')->upsert($chunk, ['id'], ['participation_man_zone', 'participation_coverage', 'participation_observed_at', 'participation_pressure', 'participation_rushers', 'participation_offense_package', 'participation_defense_dbs']);
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

    /** Parse listed personnel, preserving missing or non-eleven-player groups as unknown. */
    private function personnel(string $raw): ?array
    {
        if (in_array(trim($raw), ['', 'NA'], true)) {
            return null;
        }
        $positions = [];
        foreach (explode(',', $raw) as $part) {
            if (! preg_match('/^([1-9]|1[01]) (C|G|T|QB|RB|FB|TE|WR|CB|FS|SS|S|LB|ILB|OLB|MLB|DE|DT|NT|P|K|LS)$/', trim($part), $match)
                || isset($positions[$match[2]])) {
                throw new RuntimeException('Invalid personnel list; no rows updated.');
            }
            $positions[$match[2]] = (int) $match[1];
        }

        return array_sum($positions) === 11 ? $positions : null;
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
