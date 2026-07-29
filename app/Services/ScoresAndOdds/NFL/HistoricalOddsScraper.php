<?php

namespace App\Services\ScoresAndOdds\NFL;

use App\Services\OddsApi\Exceptions\OddsApiException;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Support\Facades\Http;

class HistoricalOddsScraper
{
    private const SPORT_PATH = '/nfl';

    private const DETAIL_PATH = '/nfl/events/%s/details';

    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly OddsApiService $oddsApiService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDate(string $date): array
    {
        $events = $this->parseDatePage($this->fetchPage($this->dateUrl($date)));

        return array_values(array_filter($events));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchEventDetails(string $eventId): ?array
    {
        return $this->parseEventDetails($this->fetchPage($this->detailUrl($eventId)), $eventId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseDatePage(string $html): array
    {
        $xpath = $this->xpath($html);
        $cards = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' event-card ')]");

        if ($cards === false) {
            return [];
        }

        $events = [];

        foreach ($cards as $card) {
            $cardId = trim((string) $card->attributes?->getNamedItem('id')?->nodeValue);
            if ($cardId === '' || ! str_contains($cardId, '.')) {
                continue;
            }

            $eventId = substr($cardId, strpos($cardId, '.') + 1);
            $rows = $xpath->query(".//tr[contains(concat(' ', normalize-space(@class), ' '), ' event-card-row ')]", $card);
            if ($rows === false || $rows->length < 2) {
                continue;
            }

            $awayRow = $rows->item(0);
            $homeRow = $rows->item(1);

            if ($awayRow === null || $homeRow === null) {
                continue;
            }

            $commenceTime = $this->firstAttributeValue(
                $xpath,
                ".//tr[contains(concat(' ', normalize-space(@class), ' '), ' event-card-header ')]//span[@data-role='localtime']",
                'data-value',
                $card
            );

            $events[] = [
                'id' => $eventId,
                'commence_time' => $commenceTime,
                'away_team' => $this->rowTeamName($xpath, $awayRow),
                'home_team' => $this->rowTeamName($xpath, $homeRow),
                'away_rotation' => $this->cleanText($this->firstNodeText($xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' team-rotation ')]", $awayRow)),
                'home_rotation' => $this->cleanText($this->firstNodeText($xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' team-rotation ')]", $homeRow)),
                'away_score' => $this->parseInteger($this->firstNodeText($xpath, ".//td[contains(concat(' ', normalize-space(@class), ' '), ' event-card-score ')]", $awayRow)),
                'home_score' => $this->parseInteger($this->firstNodeText($xpath, ".//td[contains(concat(' ', normalize-space(@class), ' '), ' event-card-score ')]", $homeRow)),
                'summary' => [
                    'away_moneyline' => $this->parseMoneylineCell($xpath, ".//td[@data-field='live-moneyline']", $awayRow),
                    'home_moneyline' => $this->parseMoneylineCell($xpath, ".//td[@data-field='live-moneyline']", $homeRow),
                    'away_spread' => $this->parsePointOddsCell($xpath, ".//td[@data-field='live-spread']", $awayRow),
                    'home_spread' => $this->parsePointOddsCell($xpath, ".//td[@data-field='live-spread']", $homeRow),
                    'away_total' => $this->parsePointOddsCell($xpath, ".//td[@data-field='live-total']", $awayRow),
                    'home_total' => $this->parsePointOddsCell($xpath, ".//td[@data-field='live-total']", $homeRow),
                ],
            ];
        }

        return $events;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseEventDetails(string $html, string $eventId): ?array
    {
        $xpath = $this->xpath($html);
        $draftKingsColumn = $this->bookColumnIndex($xpath, 'draftkings');

        if ($draftKingsColumn === null) {
            return null;
        }

        $moneylineRows = $this->tableRows($xpath, "odds-table-moneyline--{$eventId}");
        $spreadRows = $this->tableRows($xpath, "odds-table-spread--{$eventId}");
        $totalRows = $this->tableRows($xpath, "odds-table-total--{$eventId}");

        if (count($moneylineRows) < 2 || count($spreadRows) < 2 || count($totalRows) < 2) {
            return null;
        }

        $awayTeam = $this->rowTeamName($xpath, $moneylineRows[0]);
        $homeTeam = $this->rowTeamName($xpath, $moneylineRows[1]);
        $commenceTime = $this->firstAttributeValue(
            $xpath,
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' event-scoreboard ')]//span[@data-role='localtime']",
            'data-value'
        );

        $awaySpreadCell = $this->bookCellParts($xpath, $spreadRows[0], $draftKingsColumn);
        $homeSpreadCell = $this->bookCellParts($xpath, $spreadRows[1], $draftKingsColumn);
        $overCell = $this->bookCellParts($xpath, $totalRows[0], $draftKingsColumn);
        $underCell = $this->bookCellParts($xpath, $totalRows[1], $draftKingsColumn);

        $oddsData = [
            'event_id' => "scoresandodds:{$eventId}",
            'commence_time' => $commenceTime,
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'bookmakers' => [
                [
                    'key' => 'draftkings',
                    'title' => 'DraftKings',
                    'markets' => [
                        [
                            'key' => 'h2h',
                            'outcomes' => [
                                ['name' => $awayTeam, 'price' => $this->parseOddsValue($this->bookCellValue($xpath, $moneylineRows[0], $draftKingsColumn, 'data-moneyline'))],
                                ['name' => $homeTeam, 'price' => $this->parseOddsValue($this->bookCellValue($xpath, $moneylineRows[1], $draftKingsColumn, 'data-moneyline'))],
                            ],
                        ],
                        [
                            'key' => 'spreads',
                            'outcomes' => [
                                [
                                    'name' => $awayTeam,
                                    'price' => $this->parseOddsValue($awaySpreadCell['odds']),
                                    'point' => $this->parsePointValue($awaySpreadCell['value']),
                                ],
                                [
                                    'name' => $homeTeam,
                                    'price' => $this->parseOddsValue($homeSpreadCell['odds']),
                                    'point' => $this->parsePointValue($homeSpreadCell['value']),
                                ],
                            ],
                        ],
                        [
                            'key' => 'totals',
                            'outcomes' => [
                                [
                                    'name' => 'Over',
                                    'price' => $this->parseOddsValue($overCell['odds']),
                                    'point' => $this->parsePointValue($overCell['value']),
                                ],
                                [
                                    'name' => 'Under',
                                    'price' => $this->parseOddsValue($underCell['odds']),
                                    'point' => $this->parsePointValue($underCell['value']),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $oddsData['market_context'] = $this->oddsApiService->marketAvailability($oddsData);

        return [
            'id' => $eventId,
            'commence_time' => $commenceTime,
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'odds_data' => $oddsData,
        ];
    }

    private function fetchPage(string $url): string
    {
        $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->connectTimeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; PickSportsBot/1.0; +https://picksports.app)',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new OddsApiException(sprintf('ScoresAndOdds returned HTTP %d for [%s].', $response->status(), $url));
        }

        return (string) $response->body();
    }

    private function dateUrl(string $date): string
    {
        return rtrim((string) config('services.scores_and_odds.base_url', 'https://www.scoresandodds.com'), '/')
            .self::SPORT_PATH.'?date='.$date;
    }

    private function detailUrl(string $eventId): string
    {
        return rtrim((string) config('services.scores_and_odds.base_url', 'https://www.scoresandodds.com'), '/')
            .sprintf(self::DETAIL_PATH, $eventId);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /**
     * @return array<int, \DOMElement>
     */
    private function tableRows(\DOMXPath $xpath, string $tbodyId): array
    {
        $rows = $xpath->query(sprintf("//tbody[@id='%s']/tr", $tbodyId));

        if ($rows === false) {
            return [];
        }

        return array_values(array_filter(iterator_to_array($rows), fn ($row) => $row instanceof \DOMElement));
    }

    private function bookColumnIndex(\DOMXPath $xpath, string $bookAlt): ?int
    {
        $headers = $xpath->query("//th[contains(concat(' ', normalize-space(@class), ' '), ' book-logo ')]");
        if ($headers === false) {
            return null;
        }

        $index = 0;

        foreach ($headers as $header) {
            if (! $header instanceof \DOMElement) {
                continue;
            }

            $alt = strtolower(trim((string) $xpath->evaluate('string(.//img/@alt)', $header)));
            if ($alt === strtolower($bookAlt)) {
                return $index;
            }

            $index++;
        }

        return null;
    }

    /**
     * @return array{value:?string,odds:?string}
     */
    private function bookCellParts(\DOMXPath $xpath, \DOMElement $row, int $bookColumnIndex): array
    {
        $cells = $xpath->query('./td', $row);
        if ($cells === false) {
            return ['value' => null, 'odds' => null];
        }

        $cell = $cells->item($bookColumnIndex + 2);
        if (! $cell instanceof \DOMElement) {
            return ['value' => null, 'odds' => null];
        }

        return [
            'value' => $this->cleanText($this->firstNodeText($xpath, ".//*[contains(@class, 'data-value')]", $cell)),
            'odds' => $this->cleanText($this->firstNodeText($xpath, ".//*[contains(@class, 'data-odds')]", $cell)),
        ];
    }

    private function bookCellValue(\DOMXPath $xpath, \DOMElement $row, int $bookColumnIndex, string $class): ?string
    {
        $cells = $xpath->query('./td', $row);
        if ($cells === false) {
            return null;
        }

        $cell = $cells->item($bookColumnIndex + 2);
        if (! $cell instanceof \DOMElement) {
            return null;
        }

        return $this->cleanText($this->firstNodeText($xpath, sprintf(".//*[contains(@class, '%s')]", $class), $cell));
    }

    private function rowTeamName(\DOMXPath $xpath, \DOMElement $row): string
    {
        return $this->cleanText($this->firstNodeText($xpath, ".//span[contains(concat(' ', normalize-space(@class), ' '), ' team-name ')]/a/span", $row)) ?? '';
    }

    private function parseMoneylineCell(\DOMXPath $xpath, string $query, \DOMElement $row): ?int
    {
        return $this->parseOddsValue($this->firstNodeText($xpath, "{$query}//span[contains(@class, 'data-value')]", $row));
    }

    /**
     * @return array{value:?string,odds:?string}
     */
    private function parsePointOddsCell(\DOMXPath $xpath, string $query, \DOMElement $row): array
    {
        return [
            'value' => $this->cleanText($this->firstNodeText($xpath, "{$query}//span[contains(@class, 'data-value')]", $row)),
            'odds' => $this->cleanText($this->firstNodeText($xpath, "{$query}//small[contains(@class, 'data-odds')]", $row)),
        ];
    }

    private function firstNodeText(\DOMXPath $xpath, string $query, ?\DOMNode $contextNode = null): ?string
    {
        $nodes = $xpath->query($query, $contextNode);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0)?->textContent;
    }

    private function firstAttributeValue(\DOMXPath $xpath, string $query, string $attribute, ?\DOMNode $contextNode = null): ?string
    {
        $nodes = $xpath->query($query, $contextNode);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        if (! $node instanceof \DOMElement) {
            return null;
        }

        return $node->getAttribute($attribute) ?: null;
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseInteger(?string $value): ?int
    {
        $value = $this->cleanText($value);

        return is_numeric($value) ? (int) $value : null;
    }

    private function parseOddsValue(?string $value): ?int
    {
        $value = strtolower((string) $this->cleanText($value));

        if ($value === '') {
            return null;
        }

        if ($value === 'even' || $value === 'ev') {
            return 100;
        }

        $normalized = str_replace('+', '', $value);

        return is_numeric($normalized) ? (int) round((float) $normalized) : null;
    }

    private function parsePointValue(?string $value): ?float
    {
        $value = strtolower((string) $this->cleanText($value));

        if ($value === '') {
            return null;
        }

        $normalized = ltrim($value, 'ou');

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
