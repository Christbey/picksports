<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbPredictionInputQuality;
use App\Services\CFB\Predictions\CfbQuarterbackAdjustment;
use App\Services\CFB\Predictions\CfbQuarterbackEvidence;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 15:00', 'America/Chicago'));
    $this->home = Team::factory()->create();
    $this->away = Team::factory()->create();
    $this->event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addDay()]);
    $this->target = Game::factory()->create(['sport_event_id' => $this->event->id, 'season' => 2026,
        'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'game_date' => now()->addDay()]);
    $this->starter = Player::factory()->create(['espn_id' => '1001', 'full_name' => 'Starting Quarterback', 'team_id' => $this->home->id, 'position' => 'QB']);
    $this->backup = Player::factory()->create(['espn_id' => '1002', 'full_name' => 'Backup Quarterback', 'team_id' => $this->home->id, 'position' => 'QB']);
    $this->policy = (new CfbCalculationReleaseDefinition)->configuration()['quarterback_replacement'];
    $this->games = collect();
    foreach ([14, 7] as $days) {
        $game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_FINAL', 'game_date' => now()->subDays($days)]);
        $this->games->push($game);
        foreach (['boxscore', 'plays'] as $component) {
            GameDataCheck::create(['game_id' => $game->id, 'component' => $component, 'validator_version' => 'test',
                'source_path' => 'testing/boxscore.json', 'state' => 'complete', 'discrepancies' => [], 'evidence' => [], 'source_hash' => 'revision', 'accepted_at' => now()]);
        }
        foreach ([[$this->starter, 0.4], [$this->backup, -0.2]] as [$qb, $epa]) {
            PlayerStat::create(['game_id' => $game->id, 'team_id' => $this->home->id, 'player_id' => $qb->id, 'passing_attempts' => 20]);
            for ($i = 0; $i < 20; $i++) {
                Play::create(['game_id' => $game->id, 'possession_team_id' => $this->home->id, 'espn_play_id' => $game->id.'-'.$qb->id.'-'.$i,
                    'play_type' => 'Pass Reception', 'period' => 1, 'home_score' => 0, 'away_score' => 0,
                    'source_revision' => 'revision', 'true_epa' => $epa, 'is_epa_eligible' => true, 'epa_calculated_at' => now(),
                    'source_state' => ['participants' => [['type' => 'passer', 'athlete' => ['$ref' => 'https://sports.core.api.espn.com/v2/athletes/'.$qb->espn_id.'?lang=en']]]]]);
            }
        }
    }
    $this->mock(CfbDataReadiness::class)->shouldReceive('forGame')->andReturn(['ready' => true,
        'components' => ['boxscore' => ['source_hash' => 'revision'], 'plays' => ['source_hash' => 'revision']]]);
    $this->capture = fn ($injuries = null) => app(CfbQuarterbackEvidence::class)->capture($this->target, $this->home->id,
        ['last_observed_player_id' => $this->starter->id], $injuries ?? [['player_id' => $this->starter->id, 'status' => 'out']],
        CarbonImmutable::instance(now()), CarbonImmutable::instance($this->event->starts_at));
});

it('captures explicit QB participants and shrinks the backup relative to the existing QB mixture', function () {
    $evidence = ($this->capture)();
    $estimate = app(CfbQuarterbackAdjustment::class)->estimate($evidence, $this->policy);
    expect($evidence['replacement_player_id'])->toBe($this->backup->id)
        ->and($evidence['confirmed_starter'])->toBeFalse()
        ->and($evidence['baseline']['epa_per_play'])->toEqualWithDelta(0.1, 0.0001)
        ->and($estimate['applied'])->toBeTrue()
        ->and($estimate['offense_points_adjustment'])->toEqualWithDelta(-3.4286, 0.0001);
});

it('does not confuse a trick-play passer with a replacement QB or guess among multiple backups', function () {
    $wr = Player::factory()->create(['espn_id' => '1003', 'full_name' => 'Trick Passer', 'team_id' => $this->home->id, 'position' => 'WR']);
    PlayerStat::create(['player_id' => $wr->id, 'team_id' => $this->home->id, 'game_id' => $this->games->first()->id, 'passing_attempts' => 1]);
    expect(($this->capture)()['replacement_player_id'])->toBe($this->backup->id);
    $third = Player::factory()->create(['espn_id' => '1004', 'full_name' => 'Third Quarterback', 'team_id' => $this->home->id, 'position' => 'QB']);
    PlayerStat::create(['player_id' => $third->id, 'team_id' => $this->home->id, 'game_id' => $this->games->first()->id, 'passing_attempts' => 1]);
    expect(($this->capture)()['status'])->toBe('replacement_unresolved');
});

it('does not select an unavailable backup or convert a questionable starter into an absence', function () {
    expect(($this->capture)([['player_id' => $this->starter->id, 'status' => 'out'], ['player_id' => $this->backup->id, 'status' => 'out']])['status'])->toBe('replacement_unresolved')
        ->and(($this->capture)([['player_id' => $this->starter->id, 'status' => 'questionable']])['status'])->toBe('not_required')
        ->and(($this->capture)([['player_id' => $this->starter->id, 'status' => 'doubtful']])['status'])->toBe('absence_unconfirmed');
});

it('rejects future, unaccepted, mismatched-source and unidentified play evidence', function (string $failure) {
    match ($failure) {
        'future' => Play::query()->update(['epa_calculated_at' => now()->addDay()]),
        'proof' => GameDataCheck::query()->update(['accepted_at' => now()->addDay()]),
        'revision' => Play::query()->update(['source_revision' => 'wrong']),
        'identity' => Play::query()->update(['source_state' => json_encode(['participants' => []])]),
    };
    $estimate = app(CfbQuarterbackAdjustment::class)->estimate(($this->capture)(), $this->policy);
    expect($estimate['applied'])->toBeFalse()->and($estimate['offense_points_adjustment'])->toBeNull();
})->with(['future', 'proof', 'revision', 'identity']);

it('retains a hold for a backup with only one attempt rather than treating him as equal to the starter', function () {
    Play::where('true_epa', '<', 0)->delete();
    $evidence = ($this->capture)();
    $estimate = app(CfbQuarterbackAdjustment::class)->estimate($evidence, $this->policy);
    expect($estimate['status'])->toBe('insufficient_play_evidence')->and($estimate['applied'])->toBeFalse();
    $quality = CfbPredictionInputQuality::assess(['home' => ['quarterback_replacement' => $evidence, 'availability' => ['quarterback_hold' => true]]]);
    expect($quality['risk_flags'])->toContain('home_quarterback_replacement_unvalidated', 'home_quarterback_unresolved');
});

it('replaces only the generic charge for the modeled QB without modifying frozen source injuries', function () {
    $estimate = app(CfbQuarterbackAdjustment::class)->estimate(($this->capture)(), $this->policy);
    $snapshot = new EventInputSnapshotData('cfb-pregame-v1', ['home' => ['injuries' => [
        ['player_id' => $this->starter->id, 'status' => 'out'], ['player_id' => 99999, 'status' => 'out']],
        'metrics' => ['injury_adjusted_team_rating' => 1400]], 'away' => []], CarbonImmutable::instance(now()));
    $adjusted = app(CfbQuarterbackAdjustment::class)->baselineSnapshot($snapshot, ['home' => $estimate]);
    expect($snapshot->inputs['home']['injuries'])->toHaveCount(2)
        ->and($adjusted->inputs['home']['injuries'])->toHaveCount(1)
        ->and($adjusted->inputs['home']['metrics']['injury_adjusted_team_rating'])->toBeNull();
});

it('applies the same offense delta to total and the correctly signed margin after blending', function (string $side) {
    $estimate = app(CfbQuarterbackAdjustment::class)->estimate(($this->capture)(), $this->policy);
    $none = ['applied' => false, 'offense_points_adjustment' => null];
    $output = new PredictionOutput([new PredictionMarketOutput('spread', 'home', projectedLine: -7),
        new PredictionMarketOutput('total', 'combined', projectedLine: 50),
        new PredictionMarketOutput('moneyline', 'home', probability: 0.7), new PredictionMarketOutput('moneyline', 'away', probability: 0.3)]);
    $out = app(CfbQuarterbackAdjustment::class)->apply($output, ['home' => $side === 'home' ? $estimate : $none,
        'away' => $side === 'away' ? $estimate : $none], 6);
    expect($out->diagnostics['home_margin'])->toEqualWithDelta($side === 'home' ? 3.5714 : 10.4286, 0.0001)
        ->and($out->diagnostics['projected_total'])->toEqualWithDelta(46.5714, 0.0001)
        ->and($out->markets[2]->probability + $out->markets[3]->probability)->toBe(1.0);
})->with(['home', 'away']);

it('uses replacement impact once in the full calculator and retains the generic path for an older release', function () {
    $definition = new CfbCalculationReleaseDefinition;
    $config = $definition->configuration();
    $config['football_signals']['enabled'] = false;
    $config['forecast_coordination']['enabled'] = false;
    $config['independent_result_rating']['enabled'] = false;
    $evidence = ($this->capture)();
    $team = ['elo' => 1500, 'metrics' => ['record_season' => 2026, 'wins' => 8, 'losses' => 0,
        'points_per_game' => 28, 'points_allowed_per_game' => 28], 'injuries' => []];
    $inputs = ['event' => ['season' => 2026], 'home' => [...$team, 'injuries' => [['player_id' => $this->starter->id, 'status' => 'out']],
        'quarterback_replacement' => $evidence, 'availability' => ['quarterback_hold' => true]], 'away' => $team];
    $release = fn ($configuration) => new CalculationReleaseData(
        publicId: 'test', sport: 'cfb', phase: 'pregame', calculatorName: $definition->calculatorName(), releaseType: 'rules',
        semanticVersion: 'test', codeRevision: 'test', configurationHash: 'test', inputSchemaVersion: $definition->inputSchemaVersion(), configuration: $configuration);
    $snapshot = fn ($data) => new EventInputSnapshotData($definition->inputSchemaVersion(), $data, CarbonImmutable::instance(now()));
    $calculator = app(CfbCalculator::class);
    $adjusted = $calculator->calculate($snapshot($inputs), $release($config));
    $uninjured = $inputs;
    $uninjured['home']['injuries'] = [];
    $uninjured['home']['quarterback_replacement'] = ['status' => 'not_required'];
    $base = $calculator->calculate($snapshot($uninjured), $release($config));
    expect($adjusted->diagnostics['quarterback_adjustment']['home']['applied'])->toBeTrue()
        ->and($adjusted->diagnostics['availability_adjustment'])->toEqual(0)
        ->and($adjusted->diagnostics['projected_total'])->toEqualWithDelta($base->diagnostics['projected_total'] - 3.4286, 0.0001)
        ->and($adjusted->metadata['input_quality']['risk_flags'])->toContain('home_quarterback_unresolved', 'home_quarterback_replacement_unvalidated');
    $withChallenger = [...$inputs, 'scoring_challenger' => ['promoted' => true, 'state' => 'unavailable']];
    $held = $calculator->calculate($snapshot($withChallenger), $release($config));
    expect(data_get($held->diagnostics, 'quarterback_adjustment.home.applied'))->toBeFalse()
        ->and(data_get($held->diagnostics, 'quarterback_adjustment.home.status'))->toBe('challenger_personnel_overlap_unresolved');
    unset($config['quarterback_replacement']);
    $older = $calculator->calculate($snapshot($inputs), $release($config));
    expect($older->diagnostics)->not->toHaveKey('quarterback_adjustment')
        ->and($older->diagnostics['home_injuries']['out'])->toBe(1);
});

it('caps provisional effects and permits an evidence-supported upgrade instead of forcing every backup to be worse', function () {
    $evidence = ($this->capture)();
    foreach ($evidence['profiles'] as &$profile) {
        if ($profile['player_id'] === $this->backup->id) {
            $profile['epa_per_play'] = 10.0;
        }
    }
    unset($profile);
    $estimate = app(CfbQuarterbackAdjustment::class)->estimate($evidence, $this->policy);
    expect($estimate['applied'])->toBeTrue()->and($estimate['offense_points_adjustment'])->toBe(6.0);
});

it('holds an estimate when too few passing attempts have verified participant-linked EPA', function () {
    $evidence = ($this->capture)();
    foreach ($evidence['profiles'] as &$profile) {
        $profile['passing_identity_coverage'] = 0.5;
    }
    unset($profile);
    expect(app(CfbQuarterbackAdjustment::class)->estimate($evidence, $this->policy)['applied'])->toBeFalse();
});
