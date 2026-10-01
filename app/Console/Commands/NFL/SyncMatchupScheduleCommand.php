<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Services\NFL\NflGameQuarterbackIdentitySync;
use App\Services\ProviderData\ProviderSourceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncMatchupScheduleCommand extends Command
{
    protected $signature = 'nfl:sync-matchup-schedule {--season= : Current season; includes previous three seasons}';

    protected $description = 'Record verified retrospective schedule and closing-line evidence without rewriting games or forecasts';

    public function handle(NflGameQuarterbackIdentitySync $identity, ProviderSourceStorage $sources): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2002 || $season > (int) now()->year) {
            $this->error('Provide an explicit valid --season.');

            return self::FAILURE;
        }
        $path = tempnam(sys_get_temp_dir(), 'nfl-matchup-schedule-');
        $stream = null;
        try {
            $csv = Http::timeout(60)->retry(2, 1000)->get(NflGameQuarterbackIdentitySync::SOURCE_URL)->throw()->body();
            file_put_contents($path, $csv);
            $stream = fopen($path, 'r');
            $header = fgetcsv($stream, escape: '');
            $required = ['game_id', 'espn', 'season', 'game_type', 'week', 'gameday', 'gametime', 'home_team', 'away_team', 'home_score', 'away_score', 'spread_line', 'total_line', 'home_rest', 'away_rest', 'div_game', 'overtime'];
            if (! is_array($header) || array_diff($required, $header)) {
                throw new RuntimeException('Invalid schedule evidence header.');
            }
            $byEvent = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Malformed schedule source.');
                }
                $row = array_combine($header, $values);
                if ((int) $row['season'] >= $season - 3 && (int) $row['season'] <= $season && $row['espn'] !== '') {
                    $byEvent[$row['espn']][] = $row;
                }
            }
            $hash = hash('sha256', $csv);
            $sources->archive('nflverse', 'matchup-schedules', $path, ['source_url' => NflGameQuarterbackIdentitySync::SOURCE_URL]);
            $games = Game::query()->with(['homeTeam', 'awayTeam'])->whereBetween('season', [$season - 3, $season])
                ->whereIn('season_type', ['2', 'regular', 'REG'])->where('status', 'STATUS_FINAL')->get();
            $matched = 0;
            $blocked = [];
            foreach ($games as $game) {
                $rows = $byEvent[(string) $game->espn_event_id] ?? [];
                if (count($rows) !== 1 || ! $identity->matchesFinalGame($game, $rows[0])) {
                    $blocked[] = $game->id;

                    continue;
                }
                $row = $rows[0];
                if (! preg_match('/^\d{2}:\d{2}$/', $row['gametime'])) {
                    $blocked[] = $game->id;

                    continue;
                }
                $number = fn ($key) => is_numeric($row[$key]) ? (float) $row[$key] : null;
                $kickoff = CarbonImmutable::parse($row['gameday'].' '.$row['gametime'], 'America/New_York')->utc();
                $evidence = ['source' => 'nflverse_schedule_verified', 'source_game_id' => $row['game_id'],
                    'espn_event_id' => $row['espn'], 'mode' => 'retrospective_closing_line_record',
                    'kickoff_at' => $kickoff->toIso8601String(), 'home_handicap' => $number('spread_line') === null ? null : -$number('spread_line'),
                    'total' => $number('total_line'), 'home_rest' => $number('home_rest'), 'away_rest' => $number('away_rest'),
                    'division_game' => in_array($row['div_game'], ['0', '1'], true) ? $row['div_game'] === '1' : null,
                    'overtime' => in_array($row['overtime'], ['0', '1'], true) ? $row['overtime'] === '1' : null];
                if (($evidence['home_handicap'] !== null && abs($evidence['home_handicap']) > 60)
                    || ($evidence['total'] !== null && ($evidence['total'] <= 0 || $evidence['total'] >= 150))) {
                    $blocked[] = $game->id;

                    continue;
                }
                DB::table('nfl_matchup_schedule_evidence')->insertOrIgnore(['game_id' => $game->id,
                    'evidence_hash' => hash('sha256', json_encode($evidence)), 'source_sha256' => $hash,
                    'observed_at' => now(), 'evidence' => json_encode($evidence)]);
                $matched++;
            }
            $this->info("Verified matchup schedule evidence: {$matched}/{$games->count()} games.");
            if ($blocked !== []) {
                $this->error('Unresolved game identities: '.implode(', ', $blocked));

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
