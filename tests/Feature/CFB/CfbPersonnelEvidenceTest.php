<?php

use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\PreseasonTeamSignal;
use App\Models\CFB\Team;
use App\Services\CFB\Predictions\CfbEarlySeasonSpreadSupport;
use App\Services\CFB\Predictions\CfbPersonnelEvidence;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:00:00', 'America/Chicago'));
});

it('requires component provenance and rejects altered or late personnel payloads', function () {
    $team = Team::factory()->create();
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id]);
    $payload = ['teamId' => 12, 'usage' => 0.6];
    $signal = PreseasonTeamSignal::factory()->create(['team_id' => $team->id, 'season' => 2026,
        'returning_production_payload' => $payload, 'source_evidence' => ['returning_production' => [
            'source' => 'cfbd', 'season' => 2026, 'observed_at' => now()->toIso8601String(),
            'payload_field' => 'returning_production_payload', 'payload_hash' => app(CanonicalPayloadHasher::class)->hash($payload),
        ]]]);
    $get = fn () => app(CfbPersonnelEvidence::class)->forTeam($game, $team->id, CarbonImmutable::now(), CarbonImmutable::now()->addHours(4));
    expect($get()['components']['returning_production']['status'])->toBe('verified')
        ->and($get()['components']['transfers']['status'])->toBe('unverified')
        ->and($get()['coverage_complete'])->toBeFalse()->and($get()['margin_adjustment_applied'])->toBeFalse();
    $signal->update(['returning_production_payload' => ['usage' => 0.99]]);
    expect($get()['components']['returning_production']['status'])->toBe('unverified');
    $signal->forceFill(['returning_production_payload' => $payload, 'updated_at' => now()->addHours(1)])->save();
    expect($get()['components']['returning_production']['status'])->toBe('unverified');
});

it('detects a changed primary passer even when the prior quarterback left the team', function () {
    $team = Team::factory()->create();
    $former = Player::factory()->create(['team_id' => Team::factory()->create()->id, 'espn_id' => 'old', 'full_name' => 'Old QB']);
    $current = Player::factory()->create(['team_id' => $team->id, 'espn_id' => 'new', 'full_name' => 'New QB']);
    foreach ([[2025, $former, '2025-11-01'], [2026, $current, '2026-09-12']] as [$year, $player, $date]) {
        $past = Game::factory()->create(['season' => $year, 'game_date' => $date, 'status' => 'STATUS_FINAL', 'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id]);
        PlayerStat::factory()->create(['game_id' => $past->id, 'team_id' => $team->id, 'player_id' => $player->id, 'passing_attempts' => 30]);
    }
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id]);
    $result = app(CfbPersonnelEvidence::class)->forTeam($game, $team->id, CarbonImmutable::now(), CarbonImmutable::now()->addHours(4));
    expect($result['components']['quarterback']['status'])->toBe('verified')
        ->and($result['components']['quarterback']['values']['observed_primary_passer_changed'])->toBeTrue()
        ->and($result['components']['quarterback']['confirmed_starter'])->toBeFalse();
});

it('imports independently sourced head coach changes without claiming coordinator continuity', function () {
    config()->set('services.collegefootballdata.api_key', 'test');
    $team = Team::factory()->create(['cfbd_team_id' => 61, 'school' => 'Georgia']);
    Http::fake(['*coaches*' => function ($request) {
        $year = (int) $request['year'];

        return Http::response([['id' => $year, 'firstName' => $year === 2025 ? 'Old' : 'New', 'lastName' => 'Coach',
            'seasons' => [['year' => $year, 'teamId' => 61, 'school' => 'Georgia']]]]);
    }]);
    $this->artisan('cfb:sync-preseason-team-signals', ['--season' => 2026, '--include-coaches' => true,
        '--skip-returning-production' => true, '--skip-transfers' => true, '--skip-talent' => true, '--skip-recruiting' => true])->assertSuccessful();
    $signal = PreseasonTeamSignal::where('team_id', $team->id)->firstOrFail();
    expect($signal->new_head_coach)->toBeTrue()->and($signal->new_offensive_coordinator)->toBeNull()
        ->and(data_get($signal->source_evidence, 'head_coach.source'))->toBe('cfbd');
});

it('blocks the prior-season exception when the new release lacks personnel coverage', function () {
    $service = app(CfbEarlySeasonSpreadSupport::class);
    $inputs = ['require_personnel_evidence' => true, 'event' => ['season' => 2026, 'week' => 3],
        'home' => ['metrics' => ['wins' => 2]], 'away' => ['metrics' => ['wins' => 2]]];
    $result = $service->assess($inputs, ['spread_baseline' => 'fpi_points'], CarbonImmutable::now(), 'verified');
    expect($result['eligible'])->toBeFalse()->and($result['risk_flags'])
        ->toContain('home_early_season_personnel_evidence_incomplete', 'away_early_season_personnel_evidence_incomplete');
    $inputs['home']['personnel']['coverage_complete'] = true;
    $inputs['away']['personnel']['coverage_complete'] = true;
    expect($service->assess($inputs, ['spread_baseline' => 'fpi_points'], CarbonImmutable::now(), 'verified')['risk_flags'])
        ->not->toContain('home_early_season_personnel_evidence_incomplete', 'away_early_season_personnel_evidence_incomplete');
});

it('does not trust a correct hash and requested-season stamp over an explicitly wrong payload season', function () {
    $team = Team::factory()->create();
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id]);
    $attributes = ['team_id' => $team->id, 'season' => 2026];
    foreach ([
        'returning_production' => ['returning_production_payload', ['season' => 2025, 'usage' => .6]],
        'talent' => ['talent_payload', ['year' => 2025, 'talent' => 500]],
        'recruiting' => ['recruiting_payload', ['year' => 2025, 'rank' => 5, 'points' => 250]],
        'transfers' => ['transfer_portal_payload', [['season' => 2025, 'origin' => 'Georgia', 'rating' => .8]]],
    ] as $component => [$field, $payload]) {
        $attributes[$field] = $payload;
        $attributes['source_evidence'][$component] = ['source' => 'cfbd', 'season' => 2026,
            'observed_at' => now()->toIso8601String(), 'payload_field' => $field,
            'payload_hash' => app(CanonicalPayloadHasher::class)->hash($payload)];
    }
    PreseasonTeamSignal::factory()->create($attributes);
    $result = app(CfbPersonnelEvidence::class)->forTeam($game, $team->id, CarbonImmutable::now(), CarbonImmutable::now()->addHours(4));
    foreach (['returning_production', 'talent', 'recruiting', 'transfers'] as $component) {
        expect($result['components'][$component]['status'])->toBe('unverified');
    }
});
