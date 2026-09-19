<?php

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionOutput;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\CFB\CfbPlayerEvidenceService;
use App\Services\CFB\CfbTeamEvidenceService;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbPredictionInputQuality;
use Laravel\Sanctum\Sanctum;

function cfbSampleOutput(array $inputs, bool $sampleAware = true): PredictionOutput
{
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $config['inputs']['sample_aware_context'] = $sampleAware;
    unset($config['spread']['rating_baseline']);
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.2.0', 'test', 'test', 'cfb-pregame-v1', $config);

    return app(CfbCalculator::class)->calculate(new EventInputSnapshotData('cfb-pregame-v1', $inputs, now()->toImmutable()), $release);
}

function cfbSampleInputs(): array
{
    $metric = ['record_season' => 2026, 'wins' => 2, 'losses' => 0, 'points_per_game' => 40,
        'points_allowed_per_game' => 20, 'recent_form_rating' => 0, 'turnover_differential' => 0,
        'power_rating' => 10, 'fpi' => 10];

    return ['event' => ['season' => 2026], 'home' => ['elo' => 1600, 'metrics' => $metric, 'injuries' => []],
        'away' => ['elo' => 1500, 'metrics' => $metric, 'injuries' => []]];
}

it('shrinks recent form and turnover effects using the actual two game sample', function () {
    $inputs = cfbSampleInputs();
    $inputs['home']['metrics']['recent_form_rating'] = 30;
    $inputs['home']['metrics']['turnover_differential'] = 3;
    $new = cfbSampleOutput($inputs);
    $old = cfbSampleOutput($inputs, false);
    expect($new->diagnostics['recent_adjustment'])->toBe(1.0)
        ->and($old->diagnostics['recent_adjustment'])->toBe(3.0)
        ->and($new->diagnostics['turnover_adjustment'])->toBe(0.2)
        ->and($new->diagnostics['context_reliability']['home'])->toEqual(1 / 3);
});

it('blends early totals toward prior evidence while keeping fully sampled totals unchanged', function () {
    $inputs = cfbSampleInputs();
    foreach (['home', 'away'] as $side) {
        $inputs[$side]['prior_metrics'] = ['wins' => 8, 'losses' => 4, 'points_per_game' => 24, 'points_allowed_per_game' => 24];
    }
    expect(cfbSampleOutput($inputs)->diagnostics['raw_total'])->toBe(52.0);
    foreach (['home', 'away'] as $side) {
        $inputs[$side]['metrics']['wins'] = 6;
    }
    expect(cfbSampleOutput($inputs)->diagnostics['projected_total'])
        ->toBe(cfbSampleOutput($inputs, false)->diagnostics['projected_total']);
});

it('does not subtract unrelated rating scales or treat missing opposition as qualified', function () {
    $inputs = cfbSampleInputs();
    $inputs['away']['metrics'] = null;
    $result = cfbSampleOutput($inputs);
    expect($result->diagnostics['power_adjustment'])->toBe(0.0)
        ->and($result->metadata['input_quality']['qualified'])->toBeFalse()
        ->and($result->metadata['input_quality']['risk_flags'])->toContain('away_missing_team_metrics');
});

it('uses paired FPI instead of a conflicting locally derived power difference', function () {
    $inputs = cfbSampleInputs();
    $inputs['home']['metrics']['power_rating'] = -20;
    $inputs['away']['metrics']['power_rating'] = 20;
    $inputs['home']['metrics']['fpi'] = 20;
    expect(cfbSampleOutput($inputs)->diagnostics['paired_rating_family'])->toBe('fpi')
        ->and(cfbSampleOutput($inputs)->diagnostics['power_adjustment'])->toBe(1.5);
});

it('keeps actual zero scoring different from missing scoring', function () {
    $inputs = cfbSampleInputs();
    $inputs['home']['metrics']['points_per_game'] = 0;
    expect(CfbPredictionInputQuality::assess($inputs)['qualified'])->toBeTrue();
    $inputs['home']['metrics']['points_per_game'] = null;
    expect(CfbPredictionInputQuality::assess($inputs)['qualified'])->toBeFalse();
});

it('does not count prior season recent form as current season evidence', function () {
    $inputs = cfbSampleInputs();
    $inputs['home']['metrics']['record_season'] = 2025;
    $inputs['home']['metrics']['wins'] = 12;
    $inputs['home']['metrics']['recent_form_rating'] = 50;
    expect(cfbSampleOutput($inputs)->diagnostics['recent_adjustment'])->toBe(0.0);
});

function cfbWorkloadFixture(): array
{
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $other->id,
        'game_date' => '2026-09-18', 'game_time' => '23:30:00', 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED']);
    $player = Player::factory()->create(['team_id' => $team->id, 'position' => 'RB', 'espn_id' => '123456', 'full_name' => 'Sample Runner']);
    foreach (['2026-09-12', '2026-09-05', '2025-11-20', '2025-11-13', '2025-11-06'] as $i => $date) {
        $past = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $other->id,
            'game_date' => $date, 'season' => (int) substr($date, 0, 4), 'season_type' => 2, 'status' => 'STATUS_FINAL']);
        PlayerStat::create(['game_id' => $past->id, 'team_id' => $team->id, 'player_id' => $player->id,
            'rushing_yards' => 50, 'rushing_attempts' => $i < 2 ? 20 : 10]);
    }
    $prop = PlayerProp::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => $player->full_name,
        'market' => 'player_rush_yds', 'line' => 40.5, 'over_price' => -110, 'under_price' => -110, 'fetched_at' => now()]);

    return compact('game', 'player', 'prop', 'team');
}

it('separates season windows and holds material workload changes across nonoverlapping samples', function () {
    $this->travelTo(now()->setDate(2026, 9, 18));
    $fixture = cfbWorkloadFixture();
    $result = app(CfbPlayerEvidenceService::class)->forProp($fixture['prop']);
    expect($result['windows']['this_season']['games'])->toBe(2)
        ->and($result['windows']['historical']['games'])->toBe(3)
        ->and($result['windows']['last_5']['games'])->toBe(5)
        ->and($result['hold_reasons'])->toContain('material_workload_change');
});

it('keeps missing opportunities unknown and does not infer a workload change', function () {
    $this->travelTo(now()->setDate(2026, 9, 18));
    $fixture = cfbWorkloadFixture();
    PlayerStat::where('player_id', $fixture['player']->id)->update(['rushing_attempts' => null]);
    $result = app(CfbPlayerEvidenceService::class)->forProp($fixture['prop']);
    expect($result['workload']['relative_change'])->toBeNull()->and($result['hold_reasons'])->toBe([]);
});

it('holds an unavailable observed quarterback without inventing a replacement', function () {
    $this->travelTo(now()->setDate(2026, 9, 18));
    $fixture = cfbWorkloadFixture();
    $fixture['player']->update(['position' => 'QB']);
    PlayerStat::where('player_id', $fixture['player']->id)->update(['passing_attempts' => 30]);
    PlayerInjury::create(['team_id' => $fixture['team']->id, 'player_id' => $fixture['player']->id,
        'injury_key' => 'test-out', 'status' => 'Out', 'is_active' => true, 'injury_date' => '2026-09-17']);
    $evidence = app(CfbPlayerEvidenceService::class)->quarterback($fixture['game'], $fixture['team']->id);
    expect($evidence['quarterback_hold'])->toBeTrue()->and($evidence['replacement_player_id'])->toBeNull()
        ->and($evidence['confirmed_starter'])->toBeFalse();
});

it('holds a relevant injured teammate but ignores future injury reports', function () {
    $this->travelTo(now()->setDate(2026, 9, 18));
    $f = cfbWorkloadFixture();
    PlayerStat::where('player_id', $f['player']->id)->update(['rushing_attempts' => 10]);
    $teammate = Player::factory()->create(['espn_id' => '22222', 'full_name' => 'Other Runner', 'team_id' => $f['team']->id, 'position' => 'RB']);
    $last = PlayerStat::where('player_id', $f['player']->id)->first();
    PlayerStat::create(['game_id' => $last->game_id, 'team_id' => $f['team']->id, 'player_id' => $teammate->id, 'rushing_attempts' => 12]);
    $injury = PlayerInjury::create(['team_id' => $f['team']->id, 'player_id' => $teammate->id,
        'injury_key' => 'teammate', 'status' => 'Out', 'is_active' => true, 'injury_date' => '2026-09-17']);
    expect(app(CfbPlayerEvidenceService::class)->forProp($f['prop'])['hold_reasons'])->toContain('teammate_workload_unresolved');
    $injury->update(['source_updated_at' => now()->addDay()]);
    expect(app(CfbPlayerEvidenceService::class)->forProp($f['prop'])['hold_reasons'])->toBe([]);
});

it('excludes future games and transfer-team history from player evidence', function () {
    $f = cfbWorkloadFixture();
    $future = Game::factory()->create(['home_team_id' => $f['team']->id, 'away_team_id' => $f['game']->away_team_id, 'game_date' => '2026-09-20', 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL']);
    PlayerStat::create(['game_id' => $future->id, 'team_id' => $f['team']->id, 'player_id' => $f['player']->id, 'rushing_yards' => 999]);
    $oldTeam = Team::factory()->create();
    $old = Game::factory()->create(['home_team_id' => $f['team']->id, 'away_team_id' => $f['game']->away_team_id, 'game_date' => '2026-09-10', 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL']);
    PlayerStat::create(['game_id' => $old->id, 'team_id' => $oldTeam->id, 'player_id' => $f['player']->id, 'rushing_yards' => 999]);
    $evidence = app(CfbPlayerEvidenceService::class)->forProp($f['prop']);
    expect($evidence['windows']['last_10']['games'])->toBe(5)->and($evidence['windows']['last_10']['average'])->toBe(50.0);
});

it('keeps team evidence windows distinct and empty samples nullable', function () {
    $f = cfbWorkloadFixture();
    $evidence = app(CfbTeamEvidenceService::class)->forGame($f['game'], $f['team']->id);
    expect($evidence['this_season']['games'])->toBe(2)->and($evidence['historical']['games'])->toBe(3);
    $empty = app(CfbTeamEvidenceService::class)->forGame($f['game'], Team::factory()->create()->id);
    expect($empty['last_5']['points_per_game'])->toBeNull()->and($empty['last_5']['game_ids'])->toBe([]);
});

it('publishes reproducible fallback forecasts with incomplete labels and no playable signal', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-18 12:00:00', 'UTC'));
    $f = cfbWorkloadFixture();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026, 'season_type' => 'regular',
        'starts_at' => now()->addHours(11), 'status' => 'STATUS_SCHEDULED']);
    $f['game']->update(['sport_event_id' => $event->id, 'week' => 2]);
    app(CfbCalculationReleaseRegistrar::class)->register();
    $prediction = app(GenerateCanonicalPrediction::class)->execute($f['game']->fresh());
    expect($prediction->output_metadata['input_quality']['qualified'])->toBeFalse();
    $user = User::factory()->create();
    config()->set('prediction_lifecycle.canonical_reads.cfb', true);
    config()->set('subscriptions.tier_bypass_user_ids', [$user->id]);
    Sanctum::actingAs($user);
    $this->getJson('/api/v2/sports/cfb/predictions?season=2026')->assertOk()
        ->assertJsonPath('data.0.confidence_context.label', 'Incomplete inputs')
        ->assertJsonPath('data.0.confidence_level', 'unavailable')
        ->assertJsonPath('data.0.evidence_windows.home.this_season.games', 2);
    $count = CanonicalPrediction::count();
    $this->artisan('cfb:compare-prediction-models', ['--season' => 2026])->assertSuccessful();
    expect(CanonicalPrediction::count())->toBe($count);
});
