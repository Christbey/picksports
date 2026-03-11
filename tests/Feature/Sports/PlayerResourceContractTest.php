<?php

use App\Http\Resources\CFB\PlayerResource as CfbPlayerResource;
use App\Http\Resources\NBA\PlayerResource as NbaPlayerResource;
use App\Http\Resources\WCBB\PlayerResource as WcbbPlayerResource;
use App\Http\Resources\WNBA\PlayerResource as WnbaPlayerResource;
use App\Models\CFB\Player as CfbPlayer;
use App\Models\NBA\Player as NbaPlayer;
use App\Models\WCBB\Player as WcbbPlayer;
use App\Models\WNBA\Player as WnbaPlayer;
use Illuminate\Http\Request;

function resourceRequest(): Request
{
    return Request::create('/');
}

test('nba player resource exposes canonical ios-friendly aliases', function () {
    $player = new NbaPlayer([
        'id' => 12,
        'team_id' => 4,
        'espn_id' => 'espn-12',
        'first_name' => 'Jalen',
        'last_name' => 'Brunson',
        'full_name' => 'Jalen Brunson',
        'jersey_number' => '11',
        'position' => 'PG',
        'headshot_url' => 'https://example.test/jalen.png',
    ]);

    $data = NbaPlayerResource::make($player)->toArray(resourceRequest());

    expect($data)->toMatchArray([
        'first_name' => 'Jalen',
        'last_name' => 'Brunson',
        'full_name' => 'Jalen Brunson',
        'name' => 'Jalen Brunson',
        'jersey_number' => '11',
        'headshot_url' => 'https://example.test/jalen.png',
    ]);
});

test('wnba player resource includes canonical aliases alongside legacy fields', function () {
    $player = new WnbaPlayer([
        'id' => 8,
        'team_id' => 2,
        'espn_id' => 'espn-8',
        'name' => 'Aja Wilson',
        'display_name' => 'Aja Wilson',
        'short_name' => 'A. Wilson',
        'jersey' => '22',
        'position' => 'F',
        'headshot' => 'https://example.test/aja.png',
    ]);

    $data = WnbaPlayerResource::make($player)->toArray(resourceRequest());

    expect($data)->toMatchArray([
        'full_name' => 'Aja Wilson',
        'name' => 'Aja Wilson',
        'jersey' => '22',
        'jersey_number' => '22',
        'headshot' => 'https://example.test/aja.png',
        'headshot_url' => 'https://example.test/aja.png',
    ]);
});

test('wcbb player resource normalizes both old and new column variants', function () {
    $player = new WcbbPlayer([
        'id' => 3,
        'team_id' => 6,
        'espn_id' => 'espn-3',
        'first_name' => 'Caitlin',
        'last_name' => 'Clark',
        'full_name' => 'Caitlin Clark',
        'jersey_number' => '22',
        'position' => 'G',
        'year' => 'SR',
        'hometown' => 'West Des Moines, IA',
        'headshot_url' => 'https://example.test/caitlin.png',
    ]);

    $data = WcbbPlayerResource::make($player)->toArray(resourceRequest());

    expect($data)->toMatchArray([
        'first_name' => 'Caitlin',
        'last_name' => 'Clark',
        'full_name' => 'Caitlin Clark',
        'name' => 'Caitlin Clark',
        'jersey_number' => '22',
        'headshot_url' => 'https://example.test/caitlin.png',
        'headshot' => 'https://example.test/caitlin.png',
        'year' => 'SR',
    ]);
});

test('cfb player resource preserves canonical alias contract', function () {
    $player = new CfbPlayer([
        'id' => 19,
        'team_id' => 10,
        'espn_id' => 'espn-19',
        'name' => 'Arch Manning',
        'display_name' => 'Arch Manning',
        'jersey' => '16',
        'position' => 'QB',
        'headshot' => 'https://example.test/arch.png',
    ]);

    $data = CfbPlayerResource::make($player)->toArray(resourceRequest());

    expect($data)->toMatchArray([
        'full_name' => 'Arch Manning',
        'name' => 'Arch Manning',
        'jersey_number' => '16',
        'headshot_url' => 'https://example.test/arch.png',
    ]);
});
