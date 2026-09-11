<?php

namespace App\Services\NFL;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;

class ArchivedInjuryReportParser
{
    private const TEAMS = [
        'CARDINALS' => 'ARI', 'FALCONS' => 'ATL', 'RAVENS' => 'BAL', 'BILLS' => 'BUF',
        'PANTHERS' => 'CAR', 'BEARS' => 'CHI', 'BENGALS' => 'CIN', 'BROWNS' => 'CLE',
        'COWBOYS' => 'DAL', 'BRONCOS' => 'DEN', 'LIONS' => 'DET', 'PACKERS' => 'GB',
        'TEXANS' => 'HOU', 'COLTS' => 'IND', 'JAGUARS' => 'JAX', 'CHIEFS' => 'KC',
        'RAIDERS' => 'LV', 'CHARGERS' => 'LAC', 'RAMS' => 'LAR', 'DOLPHINS' => 'MIA',
        'VIKINGS' => 'MIN', 'PATRIOTS' => 'NE', 'SAINTS' => 'NO', 'GIANTS' => 'NYG',
        'JETS' => 'NYJ', 'EAGLES' => 'PHI', 'STEELERS' => 'PIT', '49ERS' => 'SF',
        'SEAHAWKS' => 'SEA', 'BUCCANEERS' => 'TB', 'TITANS' => 'TEN', 'COMMANDERS' => 'WSH',
    ];

    public function parse(string $html, string $url, int $season, string $type, int $week): array
    {
        if (parse_url($url, PHP_URL_HOST) !== 'www.nfl.com' || ! str_starts_with((string) parse_url($url, PHP_URL_PATH), '/news/')) {
            throw new RuntimeException('Expected an archived NFL.com news article.');
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($doc);
        $title = $xpath->query('//h1')->item(0)?->textContent ?? '';
        $expected = match ($type) {
            'REG' => 'Week '.$week.' injury report', 'WC' => 'Wild Card Weekend injury report',
            'DIV' => 'Divisional Round injury report', 'CON' => 'Championship.*injury report', 'SB' => 'Super Bowl [LXVI]+ injury report',
            default => throw new RuntimeException('Unsupported archive round.'),
        };
        if (! preg_match('/'.$expected.'/i', $title)) {
            throw new RuntimeException('Article title does not match the requested week/round.');
        }
        $dates = [];
        foreach ($xpath->query('//time[@datetime]') as $time) {
            $dates[] = CarbonImmutable::parse($time->getAttribute('datetime'));
        }
        if ($dates === []) {
            throw new RuntimeException('Missing article timestamp.');
        }
        // Use the latest update, never the first publication of a changing report.
        usort($dates, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());
        $published = end($dates);
        $sourceSeason = $published->month <= 3 ? $published->year - 1 : $published->year;
        if ($sourceSeason !== $season) {
            throw new RuntimeException('Article timestamp belongs to another season.');
        }
        $facts = [];
        $team = null;
        foreach ($xpath->query('//h3|//li') as $node) {
            $text = trim(preg_replace('/[\s\x{FEFF}\x{200B}]+/u', ' ', $node->textContent));
            if ($node->nodeName === 'h3') {
                $team = self::TEAMS[rtrim($text, ': ')] ?? null;

                continue;
            }
            if (! preg_match('/^(OUT|DOUBTFUL|QUESTIONABLE):\s*(.+)$/u', $text, $status)) {
                continue;
            }
            if ($team === null) {
                throw new RuntimeException('Injury list has no recognized team heading.');
            }
            // Split only before a position, never inside a multi-part injury reason.
            $entries = str_replace(['Edge ', 'C B ', 'CB Sauce Gardner calf)', 'WR Skyy Moore knee)'], ['EDGE ', 'CB ', 'CB Sauce Gardner (calf)', 'WR Skyy Moore (knee)'], $status[2]);
            $entries = preg_replace('/(?<=\))\s+(?:and\s+)?(?=[A-Z\/]{1,6}\s)/u', ', ', $entries);
            foreach (preg_split('/(?<=\)),\s*|[,.;<]\s*(?=[A-Z\/]{1,6}\s)/u', $entries) as $player) {
                if (trim($player) === '') {
                    continue;
                }
                $player = preg_replace('/\s+'.strtolower($status[1]).'$/i', '', trim($player));
                if (! preg_match('/^(?:([A-Z\/]{1,6})\s+)?([^()]+?)(?:\s*\(([^()]*)\))?\s*$/u', rtrim(trim($player), ',.;'), $parts)) {
                    throw new RuntimeException('Unparsed injury entry: '.$player);
                }
                $facts[] = [
                    'season' => $season, 'game_type' => $type, 'week' => $week, 'team' => $team,
                    'kind' => 'injury_report', 'subject' => trim($parts[2]), 'position' => $parts[1] ?: null,
                    'designation' => ucfirst(strtolower($status[1])), 'injury' => $parts[3] ?? null,
                    'source_url' => $url, 'published_at' => $published->toIso8601String(),
                    'source_text' => $text, 'source_sha256' => hash('sha256', $html), 'parser_version' => 'nfl-archive-v1',
                ];
            }
        }
        if ($facts === []) {
            throw new RuntimeException('No injury designations parsed.');
        }

        return $facts;
    }
}
