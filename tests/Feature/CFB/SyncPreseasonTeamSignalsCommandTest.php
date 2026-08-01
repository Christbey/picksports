<?php

use App\Models\CFB\PreseasonTeamSignal;
use App\Models\CFB\Team;
use App\Models\CfbdTeamMapping;
use Illuminate\Support\Facades\Http;

uses()->group('cfb', 'preseason-signals');

it('syncs preseason team signal sources while preserving manual continuity fields', function () {
    config()->set('services.collegefootballdata.api_key', 'test-cfbd-key');
    config()->set('services.collegefootballdata.base_url', 'https://api.collegefootballdata.com');

    $georgia = Team::factory()->create([
        'cfbd_team_id' => 61,
        'school' => 'Georgia',
        'abbreviation' => 'UGA',
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
    ]);

    Team::factory()->create([
        'cfbd_team_id' => 333,
        'school' => 'Alabama',
        'abbreviation' => 'ALA',
        'division' => config('cfb.teams.divisions.fbs', 'FBS'),
    ]);

    CfbdTeamMapping::query()->create([
        'cfbd_team_id' => 61,
        'cfbd_team_name' => 'Georgia',
        'cfbd_abbreviation' => 'UGA',
        'espn_team_name' => 'Georgia Bulldogs',
        'sport' => 'americanfootball_ncaaf',
        'alternate_names' => ['UGA'],
    ]);

    PreseasonTeamSignal::factory()->create([
        'team_id' => $georgia->id,
        'season' => 2026,
        'qb_continuity_classification' => PreseasonTeamSignal::QB_RETURNING_STARTER,
        'projected_starting_qb_name' => 'Curated QB',
        'new_head_coach' => false,
    ]);

    Http::fake([
        'https://api.collegefootballdata.com/player/returning*' => Http::response([
            [
                'season' => 2026,
                'teamId' => 61,
                'team' => 'Georgia',
                'percentPPA' => 0.724,
                'percentPassingPPA' => 0.812,
                'percentRushingPPA' => 0.641,
                'percentReceivingPPA' => 0.553,
                'usage' => 0.703,
                'passingUsage' => 0.884,
                'rushingUsage' => 0.577,
                'receivingUsage' => 0.499,
                'totalPPA' => 105.2,
                'totalPassingPPA' => 44.1,
                'totalRushingPPA' => 31.4,
                'totalReceivingPPA' => 29.7,
            ],
        ]),
        'https://api.collegefootballdata.com/player/portal*' => Http::response([
            [
                'season' => 2026,
                'firstName' => 'Transfer',
                'lastName' => 'Quarterback',
                'origin' => 'Alabama',
                'destination' => 'Georgia',
                'position' => 'QB',
                'rating' => 0.970,
            ],
            [
                'season' => 2026,
                'firstName' => 'Transfer',
                'lastName' => 'Receiver',
                'origin' => 'Georgia',
                'destination' => 'Alabama',
                'position' => 'WR',
                'rating' => 0.880,
            ],
        ]),
        'https://api.collegefootballdata.com/talent*' => Http::response([
            [
                'year' => 2026,
                'school' => 'Georgia',
                'talent' => 985.432,
            ],
        ]),
        'https://api.collegefootballdata.com/recruiting/teams*' => Http::response([
            [
                'year' => 2026,
                'team' => 'Georgia',
                'rank' => 2,
                'points' => 314.567,
                'avgRating' => 0.9345,
            ],
        ]),
    ]);

    $this->artisan('cfb:sync-preseason-team-signals', ['--season' => 2026])
        ->assertExitCode(0);

    $signal = PreseasonTeamSignal::query()
        ->where('team_id', $georgia->id)
        ->where('season', 2026)
        ->firstOrFail();

    expect($signal->returning_percent_ppa)->toBe('0.724')
        ->and($signal->returning_percent_passing_ppa)->toBe('0.812')
        ->and($signal->returning_total_ppa)->toBe('105.200')
        ->and($signal->incoming_transfer_count)->toBe(1)
        ->and($signal->outgoing_transfer_count)->toBe(1)
        ->and($signal->incoming_transfer_value)->toBe('0.970')
        ->and($signal->outgoing_transfer_value)->toBe('0.880')
        ->and($signal->transfer_net_value)->toBe('0.090')
        ->and($signal->transfer_qb_net_value)->toBe('0.970')
        ->and($signal->transfer_wr_net_value)->toBe('-0.880')
        ->and($signal->talent_composite)->toBe('985.432')
        ->and($signal->talent_rank)->toBe(1)
        ->and($signal->recruiting_rank)->toBe(2)
        ->and($signal->recruiting_points)->toBe('314.567')
        ->and($signal->recruiting_avg_rating)->toBe('0.9345')
        ->and($signal->qb_continuity_classification)->toBe(PreseasonTeamSignal::QB_RETURNING_STARTER)
        ->and($signal->projected_starting_qb_name)->toBe('Curated QB')
        ->and($signal->new_head_coach)->toBeFalse()
        ->and($signal->synced_at)->not->toBeNull();
});
