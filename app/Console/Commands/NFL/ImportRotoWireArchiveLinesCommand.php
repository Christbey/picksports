<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportRotoWireArchiveLinesCommand extends Command
{
    private const ARCHIVE_URL = 'https://www.rotowire.com/betting/nfl/tables/games-archive.php';

    protected $signature = 'nfl:import-rotowire-archive-lines
        {--season= : Import one NFL season}
        {--from-season= : First NFL season to import}
        {--to-season= : Last NFL season to import}
        {--limit=0 : Limit archive rows processed after filtering}
        {--dry-run : Match and report without writing snapshots}';

    protected $description = 'Import archived NFL spread and total lines from RotoWire and match them to local NFL games';

    public function handle(): int
    {
        $rows = $this->fetchRows();

        if ($rows === null) {
            return self::FAILURE;
        }

        $rows = $this->filterRows($rows);
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $matched = 0;
        $written = 0;
        $unmatched = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! $this->hasBettableLine($row)) {
                $skipped++;

                continue;
            }

            $game = $this->matchGame($row);
            if (! $game) {
                $unmatched++;
                $this->line(sprintf(
                    'Unmatched: %s week %s %s @ %s on %s',
                    $row['season'] ?? '?',
                    $row['week'] ?? '?',
                    $row['visit_team_stats_id'] ?? $row['visit_team_abbrev'] ?? '?',
                    $row['home_team_stats_id'] ?? $row['home_team_abbrev'] ?? '?',
                    $this->rowDate($row) ?? '?',
                ));

                continue;
            }

            $matched++;

            if ($this->option('dry-run')) {
                continue;
            }

            $this->storeSnapshot($game, $row);
            $written++;
        }

        $this->info(sprintf(
            'RotoWire archive lines: %d rows, %d matched, %d written, %d skipped, %d unmatched.',
            count($rows),
            $matched,
            $written,
            $skipped,
            $unmatched,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchRows(): ?array
    {
        $response = Http::timeout(30)
            ->acceptJson()
            ->get(self::ARCHIVE_URL);

        if (! $response->successful()) {
            $this->error('Unable to fetch RotoWire NFL archive lines: HTTP '.$response->status());

            return null;
        }

        $rows = $response->json();
        if (! is_array($rows)) {
            $this->error('RotoWire NFL archive response was not a JSON array.');

            return null;
        }

        return array_values(array_filter($rows, is_array(...)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function filterRows(array $rows): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($season !== null && $season !== '') {
            $fromSeason = $season;
            $toSeason = $season;
        }

        return array_values(array_filter($rows, function (array $row) use ($fromSeason, $toSeason): bool {
            $rowSeason = (int) ($row['season'] ?? 0);

            if ($fromSeason !== null && $fromSeason !== '' && $rowSeason < (int) $fromSeason) {
                return false;
            }

            if ($toSeason !== null && $toSeason !== '' && $rowSeason > (int) $toSeason) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function hasBettableLine(array $row): bool
    {
        return is_numeric($row['line'] ?? null) || is_numeric($row['game_over_under'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function matchGame(array $row): ?Game
    {
        $season = (int) ($row['season'] ?? 0);
        $date = $this->rowDate($row);
        $home = $this->normalizeTeam($row['home_team_stats_id'] ?? $row['home_team_abbrev'] ?? null);
        $away = $this->normalizeTeam($row['visit_team_stats_id'] ?? $row['visit_team_abbrev'] ?? null);

        if ($season <= 0 || ! $date || ! $home || ! $away) {
            return null;
        }

        $candidates = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->whereDate('game_date', $date)
            ->get()
            ->filter(fn (Game $game): bool => $this->normalizeTeam($game->homeTeam?->abbreviation) === $home
                && $this->normalizeTeam($game->awayTeam?->abbreviation) === $away);

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $scoreMatched = $candidates->first(fn (Game $game): bool => $this->scoresMatch($game, $row));
        if ($scoreMatched) {
            return $scoreMatched;
        }

        return Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->get()
            ->first(fn (Game $game): bool => $this->normalizeTeam($game->homeTeam?->abbreviation) === $home
                && $this->normalizeTeam($game->awayTeam?->abbreviation) === $away
                && $this->scoresMatch($game, $row));
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function scoresMatch(Game $game, array $row): bool
    {
        if (! is_numeric($row['home_team_score'] ?? null) || ! is_numeric($row['visit_team_score'] ?? null)) {
            return false;
        }

        return (int) $game->home_score === (int) $row['home_team_score']
            && (int) $game->away_score === (int) $row['visit_team_score'];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function storeSnapshot(Game $game, array $row): void
    {
        $commenceTime = $this->gameDateTime($game);
        $capturedAt = $commenceTime?->copy()->subMinutes(5) ?? Carbon::parse($this->rowDate($row));
        $oddsData = $this->oddsPayload($game, $row, $commenceTime);
        $marketContext = [
            'has_spreads' => is_numeric($row['line'] ?? null),
            'has_totals' => is_numeric($row['game_over_under'] ?? null),
            'source' => 'rotowire_archive',
            'rotowire_week' => isset($row['week']) ? (int) $row['week'] : null,
            'rotowire_weather_icon' => $row['weather_icon'] ?? null,
            'rotowire_temperature' => is_numeric($row['temperature'] ?? null) ? (float) $row['temperature'] : null,
            'rotowire_wind_speed' => is_numeric($row['wind_speed'] ?? null) ? (float) $row['wind_speed'] : null,
        ];

        GameOddsSnapshot::query()->updateOrCreate([
            'sport' => 'nfl',
            'game_table' => $game->getTable(),
            'game_id' => $game->id,
            'source' => 'rotowire_archive',
            'bookmaker_key' => 'rotowire_archive',
            'captured_at' => $capturedAt,
        ], [
            'odds_api_event_id' => $oddsData['event_id'],
            'bookmaker_title' => 'RotoWire Archive',
            'commence_time' => $commenceTime,
            'payload_hash' => hash('sha256', json_encode($oddsData)),
            'odds_data' => $oddsData,
            'market_context' => $marketContext,
        ]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function oddsPayload(Game $game, array $row, ?Carbon $commenceTime): array
    {
        $home = (string) $game->homeTeam?->abbreviation;
        $away = (string) $game->awayTeam?->abbreviation;
        $markets = [];

        if (is_numeric($row['line'] ?? null)) {
            $homeSpread = (float) $row['line'];
            $markets[] = [
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => $home, 'price' => -110, 'point' => $homeSpread],
                    ['name' => $away, 'price' => -110, 'point' => -$homeSpread],
                ],
            ];
        }

        if (is_numeric($row['game_over_under'] ?? null)) {
            $total = (float) $row['game_over_under'];
            $markets[] = [
                'key' => 'totals',
                'outcomes' => [
                    ['name' => 'Over', 'price' => -110, 'point' => $total],
                    ['name' => 'Under', 'price' => -110, 'point' => $total],
                ],
            ];
        }

        $date = $this->rowDate($row) ?? $game->game_date?->toDateString() ?? 'unknown-date';

        return [
            'event_id' => sprintf('rotowire-nfl-%s-%s-%s-%s', $game->season, $date, $away, $home),
            'commence_time' => $commenceTime?->toIso8601String(),
            'home_team' => $home,
            'away_team' => $away,
            'bookmakers' => [[
                'key' => 'rotowire_archive',
                'title' => 'RotoWire Archive',
                'markets' => $markets,
            ]],
            'market_context' => [
                'has_spreads' => is_numeric($row['line'] ?? null),
                'has_totals' => is_numeric($row['game_over_under'] ?? null),
                'archive_source' => 'rotowire',
            ],
        ];
    }

    private function gameDateTime(Game $game): ?Carbon
    {
        if (! $game->game_date) {
            return null;
        }

        $time = (string) ($game->game_time ?: '00:00:00');
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time .= ':00';
        }

        return Carbon::parse($game->game_date->toDateString().' '.$time);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function rowDate(array $row): ?string
    {
        if (! isset($row['game_date'])) {
            return null;
        }

        return Carbon::parse((string) $row['game_date'])->toDateString();
    }

    private function normalizeTeam(mixed $team): ?string
    {
        $team = strtoupper(trim((string) $team));
        if ($team === '') {
            return null;
        }

        return [
            'ARZ' => 'ARI',
            'JAC' => 'JAX',
            'LA' => 'LAR',
            'LAD' => 'LV',
            'OAK' => 'LV',
            'SD' => 'LAC',
            'STL' => 'LAR',
            'WSH' => 'WAS',
        ][$team] ?? $team;
    }
}
