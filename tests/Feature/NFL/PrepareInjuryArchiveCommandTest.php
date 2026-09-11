<?php

use App\Services\NFL\ArchivedInjuryReportParser;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

it('prepares verified facts and refuses changed archive content without replacing the output', function () {
    $html = '<h1>NFL Week 10 injury report</h1><time datetime="2025-11-07T22:58:00Z"></time><h3>49ERS</h3><li>QUESTIONABLE: QB Brock Purdy (toe)</li>';
    $url = 'https://www.nfl.com/news/test';
    $facts = app(ArchivedInjuryReportParser::class)->parse($html, $url, 2025, 'REG', 10);
    unset($facts[0]['source_sha256']);
    $manifest = tempnam(sys_get_temp_dir(), 'injury-manifest');
    $output = tempnam(sys_get_temp_dir(), 'injury-output');
    try {
        file_put_contents($manifest, json_encode([[
            'url' => $url, 'season' => 2025, 'type' => 'REG', 'week' => 10,
            'facts_sha256' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)),
        ]]));
        Http::fake([$url => Http::sequence()->push($html)->push(str_replace('QUESTIONABLE', 'OUT', $html))]);
        artisan('nfl:prepare-injury-archive', ['manifest' => $manifest, 'output' => $output])->assertSuccessful();
        $saved = file_get_contents($output);
        expect(json_decode($saved, true)[0]['designation'])->toBe('Questionable');
        artisan('nfl:prepare-injury-archive', ['manifest' => $manifest, 'output' => $output])->assertFailed();
        expect(file_get_contents($output))->toBe($saved);
    } finally {
        unlink($manifest);
        unlink($output);
    }
});
