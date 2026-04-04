<?php

namespace App\Services\Sports;

use App\Models\NFL\Team;
use App\Models\Sports\FuturesOddsSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SportsOddsHistoryNflWinTotalsImportService
{
    protected const BASE_URL = 'https://www.covers.com/sportsoddshistory/nfl-team/';

    protected const BOOKMAKER = 'sportsoddshistory';

    protected const SPORT_KEY = 'sportsoddshistory_nfl_team';

    /**
     * @param  Collection<int, Team>  $teams
     * @param  array<int, int>  $seasons
     */
    public function import(Collection $teams, array $seasons = []): int
    {
        $rows = [];
        $seasonFilter = array_values(array_unique(array_map('intval', $seasons)));

        foreach ($teams as $team) {
            $html = $this->fetchTeamPage($team);
            if ($html === null || trim($html) === '') {
                continue;
            }

            foreach ($this->parseWinTotalRows($html, $team, $seasonFilter) as $row) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return 0;
        }

        $updateColumns = [
            'row_key',
            'sport',
            'season',
            'odds_api_sport_key',
            'event_id',
            'event_name',
            'commence_time',
            'nfl_team_id',
            'bookmaker',
            'market_key',
            'market_last_update',
            'outcome_name',
            'outcome_description',
            'outcome_point',
            'price',
            'implied_probability',
            'raw_data',
            'captured_at',
            'updated_at',
        ];

        foreach (array_chunk($rows, 500) as $chunk) {
            FuturesOddsSnapshot::query()->upsert($chunk, ['snapshot_key'], $updateColumns);
        }

        return count($rows);
    }

    protected function fetchTeamPage(Team $team): ?string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; picksports importer/1.0)',
        ])->timeout(30)->get(self::BASE_URL, [
            'Team' => trim($this->teamPageName($team)),
            'sa' => 'nfl',
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    protected function teamPageName(Team $team): string
    {
        return trim(implode(' ', array_filter([
            (string) ($team->location ?? ''),
            (string) ($team->name ?? ''),
        ])));
    }

    /**
     * @param  array<int, int>  $seasonFilter
     * @return array<int, array<string, mixed>>
     */
    protected function parseWinTotalRows(string $html, Team $team, array $seasonFilter = []): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $table = $xpath->query('//h2[contains(normalize-space(.), "Regular Season Win Totals Odds")]/following::table[1]')->item(0);
        if (! $table instanceof \DOMElement) {
            return [];
        }

        $rows = [];
        foreach ($xpath->query('.//tr', $table) ?: [] as $tr) {
            if (! $tr instanceof \DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($xpath->query('./td', $tr) ?: [] as $td) {
                $cells[] = $this->cleanCellText($td->textContent ?? '');
            }

            if (count($cells) < 6) {
                continue;
            }

            $season = (int) preg_replace('/\D+/', '', $cells[0]);
            if ($season <= 0) {
                continue;
            }

            if ($seasonFilter !== [] && ! in_array($season, $seasonFilter, true)) {
                continue;
            }

            $line = $this->parseFloat($cells[1]);
            if ($line === null) {
                continue;
            }

            $capturedAt = $this->capturedAtForSeason($season);
            $common = [
                'row_key_base' => sha1(implode('|', [
                    'nfl',
                    $team->id,
                    $season,
                    'season_wins',
                    self::BOOKMAKER,
                ])),
                'sport' => 'nfl',
                'season' => $season,
                'odds_api_sport_key' => self::SPORT_KEY,
                'event_id' => "soh:nfl:season_wins:{$team->id}:{$season}",
                'event_name' => $this->teamPageName($team).' Regular Season Win Totals',
                'commence_time' => $capturedAt,
                'nfl_team_id' => $team->id,
                'bookmaker' => self::BOOKMAKER,
                'market_key' => 'season_wins',
                'market_last_update' => $capturedAt,
                'outcome_description' => $this->teamPageName($team),
                'outcome_point' => $line,
                'captured_at' => $capturedAt,
                'created_at' => $capturedAt,
                'updated_at' => $capturedAt,
                'raw_data' => [
                    'source' => 'SportsOddsHistory',
                    'source_url' => self::BASE_URL.'?Team='.urlencode($this->teamPageName($team)).'&sa=nfl',
                    'week_bet_settled' => $cells[4] ?? null,
                    'actual_wins' => $this->parseInt($cells[5] ?? null),
                    'result' => $cells[6] ?? null,
                ],
            ];

            foreach ([
                'Over' => $this->parseAmericanPrice($cells[2] ?? null),
                'Under' => $this->parseAmericanPrice($cells[3] ?? null),
            ] as $side => $price) {
                $rowKey = sha1($common['row_key_base'].'|'.$side);
                $rows[] = [
                    'snapshot_key' => sha1($rowKey.'|'.$capturedAt->toIso8601String()),
                    'row_key' => $rowKey,
                    'sport' => $common['sport'],
                    'season' => $common['season'],
                    'odds_api_sport_key' => $common['odds_api_sport_key'],
                    'event_id' => $common['event_id'],
                    'event_name' => $common['event_name'],
                    'commence_time' => $common['commence_time'],
                    'nba_team_id' => null,
                    'mlb_team_id' => null,
                    'nfl_team_id' => $common['nfl_team_id'],
                    'nfl_player_id' => null,
                    'cbb_team_id' => null,
                    'wcbb_team_id' => null,
                    'bookmaker' => $common['bookmaker'],
                    'market_key' => $common['market_key'],
                    'market_last_update' => $common['market_last_update'],
                    'outcome_name' => $side,
                    'outcome_description' => $common['outcome_description'],
                    'outcome_point' => $common['outcome_point'],
                    'price' => $price,
                    'implied_probability' => $this->toImpliedProbability($price),
                    'raw_data' => json_encode($common['raw_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'captured_at' => $common['captured_at'],
                    'created_at' => $common['created_at'],
                    'updated_at' => $common['updated_at'],
                ];
            }
        }

        return $rows;
    }

    protected function capturedAtForSeason(int $season): Carbon
    {
        return Carbon::create($season, 8, 1, 12, 0, 0, 'UTC');
    }

    protected function cleanCellText(?string $value): string
    {
        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\x{00A0}/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }

    protected function parseFloat(?string $value): ?float
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    protected function parseInt(?string $value): ?int
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || ! preg_match('/^-?\d+$/', $normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    protected function parseAmericanPrice(?string $value): ?int
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^[+-]?\d+$/', $normalized) ? (int) $normalized : null;
    }

    protected function toImpliedProbability(?int $price): ?float
    {
        if ($price === null || $price === 0) {
            return null;
        }

        if ($price > 0) {
            return round(100 / ($price + 100), 6);
        }

        return round(abs($price) / (abs($price) + 100), 6);
    }
}
