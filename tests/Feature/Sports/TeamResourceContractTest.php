<?php

use App\Http\Resources\CBB\TeamResource as CbbTeamResource;
use App\Http\Resources\MLB\TeamResource as MlbTeamResource;
use App\Http\Resources\WNBA\TeamResource as WnbaTeamResource;
use App\Models\CBB\Team as CbbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\WNBA\Team as WnbaTeam;
use Illuminate\Http\Request;

function teamResourceRequest(): Request
{
    return Request::create('/');
}

test('cbb team resource exposes shared display aliases', function () {
    $team = new CbbTeam([
        'id' => 1,
        'espn_id' => '1',
        'abbreviation' => 'KU',
        'school' => 'Kansas',
        'mascot' => 'Jayhawks',
        'conference' => 'Big 12',
        'logo_url' => 'https://example.test/kansas.png',
    ]);

    $data = CbbTeamResource::make($team)->toArray(teamResourceRequest());

    expect($data)->toMatchArray([
        'location' => 'Kansas',
        'display_name' => 'Kansas',
        'short_display_name' => 'KU',
        'logo' => 'https://example.test/kansas.png',
        'logo_url' => 'https://example.test/kansas.png',
    ]);
});

test('wnba team resource exposes stable team display fields', function () {
    $team = new WnbaTeam([
        'id' => 2,
        'espn_id' => '2',
        'abbreviation' => 'LVA',
        'location' => 'Las Vegas',
        'name' => 'Aces',
        'display_name' => 'Las Vegas Aces',
        'short_display_name' => 'Aces',
        'logo' => 'https://example.test/aces.png',
    ]);

    $data = WnbaTeamResource::make($team)->toArray(teamResourceRequest());

    expect($data)->toMatchArray([
        'display_name' => 'Las Vegas Aces',
        'short_display_name' => 'Aces',
        'logo' => 'https://example.test/aces.png',
    ]);
});

test('mlb team resource includes shared aliases without dropping mlb-specific fields', function () {
    $team = new MlbTeam([
        'id' => 3,
        'espn_id' => '3',
        'abbreviation' => 'CHC',
        'location' => 'Chicago',
        'name' => 'Cubs',
        'nickname' => 'Cubbies',
        'league' => 'NL',
        'division' => 'Central',
        'logo_url' => 'https://example.test/cubs.png',
        'elo_rating' => 1523.4,
    ]);

    $data = MlbTeamResource::make($team)->toArray(teamResourceRequest());

    expect($data)->toMatchArray([
        'display_name' => 'Chicago Cubs',
        'short_display_name' => 'CHC',
        'logo' => 'https://example.test/cubs.png',
        'logo_url' => 'https://example.test/cubs.png',
        'league' => 'NL',
    ]);
});
