<?php

namespace App\Console\Commands\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class ImportAusSportsBettingHistoricalOddsCommand extends Command
{
    protected $signature = 'nfl:import-aus-sports-betting-odds
        {file : Path to the Australia Sports Betting NFL .xlsx or .csv file}
        {--season= : Import one NFL season}
        {--from-season= : First NFL season to import}
        {--to-season= : Last NFL season to import}
        {--limit=0 : Limit rows processed after filtering}
        {--dry-run : Match and report without writing snapshots}';

    protected $description = 'Import Australia Sports Betting NFL historical moneyline, spread, and total odds';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! File::exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $rows = $this->readRows($file);
        if ($rows === []) {
            $this->error('No rows found in Australia Sports Betting file.');

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
            if (! $this->hasBettableMarket($row)) {
                $skipped++;

                continue;
            }

            $game = $this->matchGame($row);
            if (! $game) {
                $unmatched++;
                $this->line(sprintf(
                    'Unmatched: season %s %s @ %s on %s',
                    $this->rowSeason($row) ?? '?',
                    $row['away_team'] ?? '?',
                    $row['home_team'] ?? '?',
                    $this->rowDate($row)?->toDateString() ?? '?',
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
            'Australia Sports Betting NFL odds: %d rows, %d matched, %d written, %d skipped, %d unmatched.',
            count($rows),
            $matched,
            $written,
            $skipped,
            $unmatched,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(string $file): array
    {
        return str_ends_with(strtolower($file), '.csv')
            ? $this->readCsv($file)
            : $this->readXlsx($file);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $headers = array_map(fn ($header): string => $this->normalizeHeader((string) $header), $headers);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = $values[$index] ?? null;
            }

            if ($this->looksLikeDataRow($row)) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readXlsx(string $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file) !== true) {
            return [];
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sheetPath = $this->xlsxFirstSheetPath($zip) ?? 'xl/worksheets/sheet1.xml';
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();

        if (! is_string($sheetXml) || trim($sheetXml) === '') {
            return [];
        }

        $xml = simplexml_load_string($sheetXml);
        if (! $xml) {
            return [];
        }

        $matrix = [];
        foreach ($xml->sheetData->row ?? [] as $rowNode) {
            $values = [];
            foreach ($rowNode->c ?? [] as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = $this->xlsxColumnIndex($reference);
                $values[$columnIndex] = $this->xlsxCellValue($cell, $sharedStrings);
            }

            if ($values !== []) {
                ksort($values);
                $matrix[] = $values;
            }
        }

        $headerRowIndex = null;
        $headers = [];
        foreach ($matrix as $index => $values) {
            $candidate = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $values);
            if (in_array('home_team', $candidate, true) && in_array('away_team', $candidate, true)) {
                $headerRowIndex = $index;
                $headers = $candidate;
                break;
            }
        }

        if ($headerRowIndex === null) {
            return [];
        }

        $rows = [];
        foreach (array_slice($matrix, $headerRowIndex + 1) as $values) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = $values[$index] ?? null;
            }

            if ($this->looksLikeDataRow($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || trim($xml) === '') {
            return [];
        }

        $shared = simplexml_load_string($xml);
        if (! $shared) {
            return [];
        }

        $strings = [];
        foreach ($shared->si ?? [] as $item) {
            $parts = [];
            if (isset($item->t)) {
                $parts[] = (string) $item->t;
            }

            foreach ($item->r ?? [] as $run) {
                $parts[] = (string) ($run->t ?? '');
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function xlsxFirstSheetPath(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbook) || ! is_string($relationships)) {
            return null;
        }

        $workbookXml = simplexml_load_string($workbook);
        $relationshipXml = simplexml_load_string($relationships);
        if (! $workbookXml || ! $relationshipXml) {
            return null;
        }

        $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = $workbookXml->xpath('//sheet')[0] ?? null;
        if (! $sheet) {
            return null;
        }

        $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($attributes['id'] ?? '');
        if ($relationshipId === '') {
            return null;
        }

        foreach ($relationshipXml->Relationship ?? [] as $relationship) {
            if ((string) ($relationship['Id'] ?? '') !== $relationshipId) {
                continue;
            }

            $target = ltrim((string) ($relationship['Target'] ?? ''), '/');
            if ($target === '') {
                return null;
            }

            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): mixed
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            $index = (int) ($cell->v ?? -1);

            return $sharedStrings[$index] ?? null;
        }

        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        return isset($cell->v) ? (string) $cell->v : null;
    }

    private function xlsxColumnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/i', $reference, $matches);
        $letters = strtoupper($matches[1] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?: '';
        $header = trim($header, '_');

        return match ($header) {
            'date', 'game_date' => 'date',
            'home', 'home_team', 'home_team_name' => 'home_team',
            'away', 'away_team', 'away_team_name' => 'away_team',
            'home_score', 'home_pts', 'home_points' => 'home_score',
            'away_score', 'away_pts', 'away_points' => 'away_score',
            'playoff_game', 'play_off_game', 'play_off_game_y_n', 'playoff_game_y_n' => 'playoff_game',
            'home_odds', 'home_odds_decimal', 'home_odds_close' => 'home_odds',
            'away_odds', 'away_odds_decimal', 'away_odds_close' => 'away_odds',
            'home_line', 'line', 'home_line_close' => 'home_line',
            'home_line_odds_close' => 'home_line_odds',
            'away_line_odds_close' => 'away_line_odds',
            'over_under_market', 'over_under', 'total', 'total_line', 'total_score_close' => 'over_under_market',
            'total_score_over_close' => 'over_odds',
            'total_score_under_close' => 'under_odds',
            default => $header,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function looksLikeDataRow(array $row): bool
    {
        return $this->rowDate($row) !== null
            && trim((string) ($row['home_team'] ?? '')) !== ''
            && trim((string) ($row['away_team'] ?? '')) !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
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
            $rowSeason = $this->rowSeason($row);
            if ($rowSeason === null) {
                return false;
            }

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
     * @param  array<string, mixed>  $row
     */
    private function hasBettableMarket(array $row): bool
    {
        return $this->decimalToAmerican($row['home_odds'] ?? null) !== null
            || $this->decimalToAmerican($row['away_odds'] ?? null) !== null
            || is_numeric($row['home_line'] ?? null)
            || is_numeric($row['over_under_market'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchGame(array $row): ?Game
    {
        $date = $this->rowDate($row);
        $season = $this->rowSeason($row);
        $home = $this->normalizeTeamName($row['home_team'] ?? null);
        $away = $this->normalizeTeamName($row['away_team'] ?? null);

        if (! $date || $season === null || ! $home || ! $away) {
            return null;
        }

        $candidates = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->whereDate('game_date', $date->toDateString())
            ->get()
            ->filter(fn (Game $game): bool => $this->teamMatches($game->homeTeam, $home)
                && $this->teamMatches($game->awayTeam, $away));

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
            ->first(fn (Game $game): bool => $this->teamMatches($game->homeTeam, $home)
                && $this->teamMatches($game->awayTeam, $away)
                && $this->scoresMatch($game, $row));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function storeSnapshot(Game $game, array $row): void
    {
        $commenceTime = $this->gameDateTime($game);
        $capturedAt = $commenceTime?->copy()->subMinutes(5) ?? $this->rowDate($row);
        $oddsData = $this->oddsPayload($game, $row, $commenceTime);
        $marketContext = [
            'has_h2h' => $this->decimalToAmerican($row['home_odds'] ?? null) !== null
                && $this->decimalToAmerican($row['away_odds'] ?? null) !== null,
            'has_spreads' => is_numeric($row['home_line'] ?? null),
            'has_totals' => is_numeric($row['over_under_market'] ?? null),
            'source' => 'aus_sports_betting',
            'line_type' => 'closing',
            'home_decimal_odds' => $this->numericValue($row['home_odds'] ?? null),
            'away_decimal_odds' => $this->numericValue($row['away_odds'] ?? null),
            'home_line' => $this->numericValue($row['home_line'] ?? null),
            'total_line' => $this->numericValue($row['over_under_market'] ?? null),
            'home_line_decimal_odds' => $this->numericValue($row['home_line_odds'] ?? null),
            'away_line_decimal_odds' => $this->numericValue($row['away_line_odds'] ?? null),
            'over_decimal_odds' => $this->numericValue($row['over_odds'] ?? null),
            'under_decimal_odds' => $this->numericValue($row['under_odds'] ?? null),
            'playoff_game' => $this->truthy($row['playoff_game'] ?? null),
        ];

        GameOddsSnapshot::query()->updateOrCreate([
            'sport' => 'nfl',
            'game_table' => $game->getTable(),
            'game_id' => $game->id,
            'source' => 'aus_sports_betting',
            'bookmaker_key' => 'aus_sports_betting',
            'captured_at' => $capturedAt,
        ], [
            'odds_api_event_id' => $oddsData['event_id'],
            'bookmaker_title' => 'Australia Sports Betting',
            'commence_time' => $commenceTime,
            'payload_hash' => hash('sha256', json_encode($oddsData)),
            'odds_data' => $oddsData,
            'market_context' => $marketContext,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function oddsPayload(Game $game, array $row, ?Carbon $commenceTime): array
    {
        $home = (string) $game->homeTeam?->abbreviation;
        $away = (string) $game->awayTeam?->abbreviation;
        $markets = [];
        $homeMoneyline = $this->decimalToAmerican($row['home_odds'] ?? null);
        $awayMoneyline = $this->decimalToAmerican($row['away_odds'] ?? null);
        $homeLineOdds = $this->decimalToAmerican($row['home_line_odds'] ?? null) ?? -110;
        $awayLineOdds = $this->decimalToAmerican($row['away_line_odds'] ?? null) ?? -110;
        $overOdds = $this->decimalToAmerican($row['over_odds'] ?? null) ?? -110;
        $underOdds = $this->decimalToAmerican($row['under_odds'] ?? null) ?? -110;

        if ($homeMoneyline !== null && $awayMoneyline !== null) {
            $markets[] = [
                'key' => 'h2h',
                'outcomes' => [
                    ['name' => $away, 'price' => $awayMoneyline],
                    ['name' => $home, 'price' => $homeMoneyline],
                ],
            ];
        }

        if (is_numeric($row['home_line'] ?? null)) {
            $homeSpread = (float) $row['home_line'];
            $markets[] = [
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => $home, 'price' => $homeLineOdds, 'point' => $homeSpread],
                    ['name' => $away, 'price' => $awayLineOdds, 'point' => -$homeSpread],
                ],
            ];
        }

        if (is_numeric($row['over_under_market'] ?? null)) {
            $total = (float) $row['over_under_market'];
            $markets[] = [
                'key' => 'totals',
                'outcomes' => [
                    ['name' => 'Over', 'price' => $overOdds, 'point' => $total],
                    ['name' => 'Under', 'price' => $underOdds, 'point' => $total],
                ],
            ];
        }

        $date = $this->rowDate($row)?->toDateString() ?? $game->game_date?->toDateString() ?? 'unknown-date';

        return [
            'event_id' => sprintf('aus-sports-betting-nfl-%s-%s-%s-%s', $game->season, $date, $away, $home),
            'commence_time' => $commenceTime?->toIso8601String(),
            'home_team' => $home,
            'away_team' => $away,
            'bookmakers' => [[
                'key' => 'aus_sports_betting',
                'title' => 'Australia Sports Betting',
                'markets' => $markets,
            ]],
            'market_context' => [
                'has_h2h' => $homeMoneyline !== null && $awayMoneyline !== null,
                'has_spreads' => is_numeric($row['home_line'] ?? null),
                'has_totals' => is_numeric($row['over_under_market'] ?? null),
                'archive_source' => 'aus_sports_betting',
                'line_type' => 'closing',
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
     * @param  array<string, mixed>  $row
     */
    private function rowDate(array $row): ?Carbon
    {
        $value = $row['date'] ?? null;
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value));
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowSeason(array $row): ?int
    {
        $date = $this->rowDate($row);
        if (! $date) {
            return null;
        }

        return $date->month <= 2 ? $date->year - 1 : $date->year;
    }

    private function normalizeTeamName(mixed $team): ?string
    {
        $team = strtolower(trim((string) $team));
        if ($team === '') {
            return null;
        }

        $team = str_replace(['&', '.', "'"], ['and', '', ''], $team);
        $team = preg_replace('/[^a-z0-9]+/', ' ', $team) ?: '';
        $team = trim($team);

        return [
            'arizona cardinals' => 'cardinals',
            'atlanta falcons' => 'falcons',
            'baltimore ravens' => 'ravens',
            'buffalo bills' => 'bills',
            'carolina panthers' => 'panthers',
            'chicago bears' => 'bears',
            'cincinnati bengals' => 'bengals',
            'cleveland browns' => 'browns',
            'dallas cowboys' => 'cowboys',
            'denver broncos' => 'broncos',
            'detroit lions' => 'lions',
            'green bay packers' => 'packers',
            'houston texans' => 'texans',
            'indianapolis colts' => 'colts',
            'jacksonville jaguars' => 'jaguars',
            'kansas city chiefs' => 'chiefs',
            'las vegas raiders' => 'raiders',
            'oakland raiders' => 'raiders',
            'los angeles chargers' => 'chargers',
            'san diego chargers' => 'chargers',
            'los angeles rams' => 'rams',
            'st louis rams' => 'rams',
            'miami dolphins' => 'dolphins',
            'minnesota vikings' => 'vikings',
            'new england patriots' => 'patriots',
            'new orleans saints' => 'saints',
            'new york giants' => 'giants',
            'ny giants' => 'giants',
            'new york jets' => 'jets',
            'ny jets' => 'jets',
            'philadelphia eagles' => 'eagles',
            'pittsburgh steelers' => 'steelers',
            'san francisco 49ers' => '49ers',
            'seattle seahawks' => 'seahawks',
            'tampa bay buccaneers' => 'buccaneers',
            'tennessee titans' => 'titans',
            'washington commanders' => 'commanders',
            'washington football team' => 'commanders',
            'washington redskins' => 'commanders',
        ][$team] ?? $team;
    }

    private function teamMatches(mixed $team, string $needle): bool
    {
        $names = [
            $this->normalizeTeamName($team?->name ?? null),
            $this->normalizeTeamName(trim(((string) ($team?->location ?? '')).' '.((string) ($team?->name ?? '')))),
            $this->normalizeTeamName($team?->display_name ?? null),
            $this->normalizeTeamName($team?->short_display_name ?? null),
            $this->normalizeTeamName($team?->abbreviation ?? null),
        ];

        return in_array($needle, array_filter($names), true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function scoresMatch(Game $game, array $row): bool
    {
        if (! is_numeric($row['home_score'] ?? null) || ! is_numeric($row['away_score'] ?? null)) {
            return false;
        }

        return (int) $game->home_score === (int) $row['home_score']
            && (int) $game->away_score === (int) $row['away_score'];
    }

    private function decimalToAmerican(mixed $value): ?int
    {
        $decimal = $this->numericValue($value);
        if ($decimal === null || $decimal <= 1.0) {
            return null;
        }

        return $decimal >= 2.0
            ? (int) round(($decimal - 1.0) * 100)
            : (int) round(-100 / ($decimal - 1.0));
    }

    private function numericValue(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = str_replace([',', '$'], '', trim((string) $value));

        return is_numeric($value) ? (float) $value : null;
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 't', 'yes', 'y'], true);
    }
}
