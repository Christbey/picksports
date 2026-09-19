<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbCalculator;
use App\Services\CFB\Predictions\CfbInputSnapshotBuilder;
use App\Services\CFB\Predictions\CfbPointInTimeElo;
use App\Services\CFB\Predictions\CfbPredictionInputQuality;

it('separates FPI baseline, local feature coverage and uncalibrated market confidence', function () {
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.4.0', 'test', 'test', 'cfb-pregame-v1', $config);
    $team = ['elo' => 1500, 'metrics' => ['record_season' => 2026, 'wins' => 2, 'losses' => 0,
        'fpi' => 10, 'points_per_game' => 28, 'points_allowed_per_game' => 21], 'injuries' => []];
    $snapshot = new EventInputSnapshotData('cfb-pregame-v1', ['event' => ['season' => 2026], 'home' => $team, 'away' => $team], now()->toImmutable());
    $result = app(CfbCalculator::class)->calculate($snapshot, $release);
    expect($result->diagnostics['feature_coverage']['elo']['role'])->toBe('comparison_and_injury_reference')
        ->and($result->diagnostics['feature_coverage']['not_directly_applied'])->toContain('returning_production', 'weather')
        ->and($result->diagnostics['feature_coverage']['calibration']['cover_probability'])->toBeNull()
        ->and(collect($result->markets)->firstWhere('marketType', 'spread')->confidenceScore)->toBeNull()
        ->and(collect($result->markets)->firstWhere('marketType', 'total')->confidenceScore)->toBeNull();
    unset($config['inputs']['auditable_feature_coverage']);
    $legacy = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.3.0', 'test', 'test', 'cfb-pregame-v1', $config);
    $previous = app(CfbCalculator::class)->calculate($snapshot, $legacy);
    expect(collect($previous->markets)->firstWhere('marketType', 'spread')->confidenceScore)->toBeGreaterThan(50)
        ->and(collect($previous->markets)->firstWhere('marketType', 'spread')->projectedLine)
        ->toBe(collect($result->markets)->firstWhere('marketType', 'spread')->projectedLine);
});

it('blocks required Elo evidence while leaving older snapshot policies unchanged', function () {
    $team = ['metrics' => ['wins' => 6, 'losses' => 0, 'points_per_game' => 28, 'points_allowed_per_game' => 21]];
    $inputs = ['require_versioned_elo' => true, 'home' => $team, 'away' => $team];
    expect(CfbPredictionInputQuality::assess($inputs)['risk_flags'])
        ->toContain('home_unverified_elo_provenance', 'away_unverified_elo_provenance');
    foreach (['home', 'away'] as $side) {
        $inputs[$side]['elo_evidence'] = ['qualified' => true];
    }
    expect(CfbPredictionInputQuality::assess($inputs)['qualified'])->toBeTrue();
    unset($inputs['require_versioned_elo'], $inputs['home']['elo_evidence'], $inputs['away']['elo_evidence']);
    expect(CfbPredictionInputQuality::assess($inputs)['qualified'])->toBeTrue();
});

it('suppresses absolute injury ratings based on an unregressed preseason team rating', function () {
    $home = Team::factory()->create(['elo_rating' => 2200]);
    $away = Team::factory()->create(['elo_rating' => 1500]);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026,
        'starts_at' => now()->addDay(), 'neutral_site' => true]);
    Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'week' => 1,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $event->starts_at]);
    foreach ([$home, $away] as $team) {
        TeamMetric::create(['team_id' => $team->id, 'season' => 2026,
            'wins' => 1, 'losses' => 0, 'points_per_game' => 28, 'points_allowed_per_game' => 21,
            'injury_adjusted_team_rating' => $team->elo_rating, 'calculation_date' => now()->toDateString()]);
    }
    $this->mock(CfbPointInTimeElo::class)->shouldReceive('forTeam')->twice()
        ->andReturnUsing(fn ($teamId) => ['rating' => $teamId === $home->id ? 1570 : 1500,
            'evidence' => ['qualified' => true, 'source_kind' => 'derived_season_initialization', 'observed_at' => now()->subDay()->toIso8601String()]]);
    $config = app(CfbCalculationReleaseDefinition::class)->configuration();
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.4.0', 'test', 'test', 'cfb-pregame-v1', $config);
    $snapshot = app(CfbInputSnapshotBuilder::class)->build($event, $release);
    $result = app(CfbCalculator::class)->calculate($snapshot, $release);
    expect($snapshot->inputs['home']['metrics']['injury_adjusted_team_rating'])->toBeNull()
        ->and($snapshot->inputs['home']['elo'])->toBe(1570)
        ->and($result->diagnostics['injury_rating_adjustment'])->toBe(0.0)
        ->and($result->diagnostics['feature_coverage']['injury_rating_evidence']['home']['status'])->toBe('metric_baseline_unaligned')
        ->and($result->diagnostics['feature_coverage']['injury_rating_evidence']['home']['direct_injury_records_retained'])->toBeTrue();
});

it('accepts same-day prior-season refresh only when observed before kickoff', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-09-19 10:00:00'));
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'season' => 2026,
        'starts_at' => now()->addHour()]);
    Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'week' => 3,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at]);
    $prior = TeamMetric::create(['team_id' => $home->id, 'season' => 2025,
        'wins' => 8, 'losses' => 4, 'points_per_game' => 30, 'points_allowed_per_game' => 21,
        'calculation_date' => now()->toDateString()]);
    $release = new CalculationReleaseData('test', 'cfb', 'pregame', 'cfb-pregame-rules', 'rules', '1.4.0', 'test', 'test', 'cfb-pregame-v1', app(CfbCalculationReleaseDefinition::class)->configuration());
    $builder = app(CfbInputSnapshotBuilder::class);
    expect($builder->build($event, $release)->inputs['home']['prior_metric_evidence']['metric_id'])->toBe($prior->id);
    $this->travelTo(now()->addHours(2));
    $prior->touch();
    expect($builder->build($event->fresh(), $release)->inputs['home']['prior_metrics'])->toBeNull();
});
