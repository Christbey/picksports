<?php

use App\Services\NFL\ArchivedInjuryReportParser;

function archivedNflHtml(string $body, string $date = '2025-11-07T22:58:00Z'): string
{
    return '<h1>NFL Week 10 injury report: Player statuses</h1><time datetime="2025-11-05T20:00:00Z"></time><time datetime="'.$date.'"></time>'.$body;
}

it('parses designations, multi-part injuries, dual positions and missing positions without inventing participation', function () {
    $html = archivedNflHtml('<h3>49ERS</h3><ul><li>QUESTIONABLE: QB Brock Purdy (toe/shoulder), WR Example Player (knee, ankle)</li></ul><h3>RAMS:</h3><ul><li>OUT: FB/DL Scott Matlock (chest), Aidan O’Connell (right wrist)</li></ul>');
    $facts = (new ArchivedInjuryReportParser)->parse($html, 'https://www.nfl.com/news/report', 2025, 'REG', 10);
    expect($facts)->toHaveCount(4)
        ->and($facts[0]['team'])->toBe('SF')->and($facts[0]['designation'])->toBe('Questionable')
        ->and($facts[0])->not->toHaveKey('participation')
        ->and($facts[0]['published_at'])->toBe('2025-11-07T22:58:00+00:00')
        ->and($facts[1]['injury'])->toBe('knee, ankle')
        ->and($facts[2]['position'])->toBe('FB/DL')
        ->and($facts[3]['position'])->toBeNull();
});

it('retains raw evidence when repairing known punctuation errors', function () {
    $html = archivedNflHtml('<h3>COLTS</h3><li>OUT: CB Sauce Gardner calf), C B Example Player (ankle). Edge Another Player (knee)</li>');
    $facts = (new ArchivedInjuryReportParser)->parse($html, 'https://www.nfl.com/news/report', 2025, 'REG', 10);
    expect($facts)->toHaveCount(3)->and($facts[0]['injury'])->toBe('calf')
        ->and($facts[0]['source_text'])->toContain('Sauce Gardner calf)')
        ->and($facts[2]['position'])->toBe('EDGE');
});

it('rejects a reused URL from another season, wrong week, unknown team and malformed entry', function ($body, $date, $week) {
    expect(fn () => (new ArchivedInjuryReportParser)->parse(archivedNflHtml($body, $date), 'https://www.nfl.com/news/report', 2025, 'REG', $week))->toThrow(RuntimeException::class);
})->with([
    ['<h3>49ERS</h3><li>OUT: QB Test Player (toe)</li>', '2026-11-07T22:58:00Z', 10],
    ['<h3>49ERS</h3><li>OUT: QB Test Player (toe)</li>', '2025-11-07T22:58:00Z', 9],
    ['<h3>UNKNOWN</h3><li>OUT: QB Test Player (toe)</li>', '2025-11-07T22:58:00Z', 10],
    ['<h3>49ERS</h3><li>OUT: QB Test Player (toe</li>', '2025-11-07T22:58:00Z', 10],
]);
