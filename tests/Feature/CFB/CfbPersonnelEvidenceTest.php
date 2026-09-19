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

it('uses provider passing usage with separate identities while preserving curated quarterback fields', function () {
    config()->set('services.collegefootballdata.api_key', 'test');
    $team = Team::factory()->create(['school' => 'Georgia']);
    $signal = PreseasonTeamSignal::factory()->create(['team_id' => $team->id, 'season' => 2026, 'qb_continuity_confidence' => .7]);
    Http::fake(['*stats/player/season*' => function ($request) {
        expect($request['category'])->toBe('passing');
        $year = (int) $request['year'];

        return Http::response([
            ['season' => $year, 'playerId' => 'cfbd-'.$year, 'player' => 'Passer', 'team' => 'Georgia', 'category' => 'passing', 'statType' => 'ATT', 'stat' => '100'],
            ['season' => $year, 'playerId' => 'backup', 'player' => 'Backup', 'team' => 'Georgia', 'category' => 'passing', 'statType' => 'ATT', 'stat' => '10'],
            ['season' => $year, 'playerId' => 'yards-only', 'player' => 'Other', 'team' => 'Georgia', 'category' => 'passing', 'statType' => 'YDS', 'stat' => '3000'],
        ]);
    }]);
    $this->artisan('cfb:sync-preseason-team-signals', ['--season' => 2026, '--include-quarterbacks' => true, '--require-data' => true,
        '--skip-returning-production' => true, '--skip-transfers' => true, '--skip-talent' => true, '--skip-recruiting' => true])->assertSuccessful();
    expect((float) $signal->fresh()->qb_continuity_confidence)->toBe(.7);
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id]);
    $get = fn () => app(CfbPersonnelEvidence::class)->forTeam($game, $team->id, CarbonImmutable::now(), CarbonImmutable::now()->addHours(4))['components']['quarterback'];
    expect($get()['status'])->toBe('verified')->and($get()['source'])->toBe('cfbd_season_passing_usage')
        ->and($get()['values']['current']['cfbd_player_id'])->toBe('cfbd-2026')
        ->and($get()['values']['observed_primary_passer_changed'])->toBeTrue()->and($get()['confirmed_starter'])->toBeFalse();
    $payload = $signal->fresh()->qb_season_usage_payload;
    $payload['current'][1]['stat'] = '100';
    $evidence = $signal->fresh()->source_evidence;
    $evidence['quarterback_usage']['payload_hash'] = app(CanonicalPayloadHasher::class)->hash($payload);
    $signal->update(['qb_season_usage_payload' => $payload, 'source_evidence' => $evidence]);
    expect($get()['status'])->toBe('unresolved');
    $payload['current'][1]['stat'] = '10';
    $signal->update(['qb_season_usage_payload' => $payload]);
    expect($get()['status'])->toBe('unresolved'); // A mismatched hash cannot recover verification.
});

it('rejects absent or wrong-season quarterback feeds without refreshing previous source evidence', function ($wrongYear) {
    config()->set('services.collegefootballdata.api_key', 'test');
    $team = Team::factory()->create(['school' => 'Georgia']);
    $signal = PreseasonTeamSignal::factory()->create(['team_id' => $team->id, 'season' => 2026,
        'qb_season_usage_payload' => ['preserved' => true], 'source_evidence' => ['quarterback_usage' => ['observed_at' => '2026-09-18']]]);
    $before = $signal->fresh()->getAttributes();
    Http::fake(['*stats/player/season*' => Http::response($wrongYear ? [
        ['season' => 2024, 'playerId' => 'p', 'team' => 'Georgia', 'category' => 'passing', 'statType' => 'ATT', 'stat' => '100'],
    ] : [])]);
    $this->artisan('cfb:sync-preseason-team-signals', ['--season' => 2026, '--include-quarterbacks' => true, '--require-data' => true,
        '--skip-returning-production' => true, '--skip-transfers' => true, '--skip-talent' => true, '--skip-recruiting' => true])->assertFailed();
    expect($signal->fresh()->getAttributes())->toBe($before);
})->with([false, true]);

it('verifies unrated transfer identities but leaves their values unknown', function () {
    config()->set('services.collegefootballdata.api_key', 'test');
    $team = Team::factory()->create(['school' => 'Georgia']);
    Http::fake(['*player/portal*' => Http::response([
        ['season' => 2026, 'firstName' => 'Unrated', 'lastName' => 'Transfer', 'origin' => 'Georgia', 'destination' => 'Other', 'position' => 'QB', 'rating' => null, 'stars' => null],
        ['season' => 2026, 'firstName' => 'Rated', 'lastName' => 'Transfer', 'origin' => 'Other', 'destination' => 'Georgia', 'position' => 'QB', 'rating' => .9],
    ])]);
    $this->artisan('cfb:sync-preseason-team-signals', ['--season' => 2026, '--require-data' => true,
        '--skip-returning-production' => true, '--skip-talent' => true, '--skip-recruiting' => true])->assertSuccessful();
    $signal = PreseasonTeamSignal::where('team_id', $team->id)->firstOrFail();
    expect($signal->outgoing_transfer_value)->toBeNull()->and($signal->transfer_net_value)->toBeNull()->and($signal->transfer_qb_net_value)->toBeNull();
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id]);
    $result = app(CfbPersonnelEvidence::class)->forTeam($game, $team->id, CarbonImmutable::now(), CarbonImmutable::now()->addHours(4))['components']['transfers'];
    expect($result['status'])->toBe('verified')->and($result['rating_coverage']['rated'])->toBe(1)
        ->and($result['rating_coverage']['unrated'])->toBe(1)->and($result['rating_coverage']['unknown_ratings_imputed'])->toBeFalse();
});

it('holds provider quarterback evidence with invalid nested season or observation time', function ($invalid) {
    $team = Team::factory()->create();
    $game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-09-19', 'home_team_id' => $team->id, 'away_team_id' => Team::factory()->create()->id]);
    $row = fn ($year) => ['season' => $year, 'playerId' => 'p-'.$year, 'category' => 'passing', 'statType' => 'ATT', 'stat' => '100'];
    $payload = ['season' => 2026, 'team_id' => $team->id, 'identity_namespace' => 'cfbd', 'current' => [$row(2026)], 'prior' => [$row($invalid === 'season' ? 2024 : 2025)]];
    $observed = match ($invalid) {
        'stale' => now()->subDays(8), 'future' => now()->addMinute(), default => now()
    };
    PreseasonTeamSignal::factory()->create(['team_id' => $team->id, 'season' => 2026, 'qb_season_usage_payload' => $payload,
        'source_evidence' => ['quarterback_usage' => ['source' => 'cfbd', 'season' => 2026, 'observed_at' => $observed->toIso8601String(),
            'payload_field' => 'qb_season_usage_payload', 'payload_hash' => app(CanonicalPayloadHasher::class)->hash($payload)]]]);
    $result = app(CfbPersonnelEvidence::class)->forTeam($game, $team->id, CarbonImmutable::now(), CarbonImmutable::now()->addHours(4));
    expect($result['components']['quarterback']['status'])->toBe('unresolved');
})->with(['season', 'stale', 'future']);
