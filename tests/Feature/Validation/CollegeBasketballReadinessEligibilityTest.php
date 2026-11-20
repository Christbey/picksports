<?php

use App\Actions\Validation\Checks\PredictionCompletenessCheck;
use App\Actions\Validation\Checks\UpcomingGameReadinessCheck;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\SportEvent;
use App\Services\WCBB\Predictions\WcbbPregameInputReadiness;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->travelTo('2026-11-18 12:00:00');
    config(['prediction_lifecycle.canonical_pipeline.cbb' => true, 'prediction_lifecycle.canonical_pipeline.wcbb' => true]);
});
afterEach(fn () => $this->travelBack());

function collegeReadinessGame(string $sport, array $homeAttributes = []): Model
{
    $gameClass = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $teamClass = 'App\\Models\\'.strtoupper($sport).'\\Team';
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2027, 'starts_at' => now()->addDay()]);

    return $gameClass::factory()->create([
        'sport_event_id' => $event->id, 'season' => 2027, 'season_type' => 2,
        'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => $teamClass::factory()->create($homeAttributes)->id,
        'away_team_id' => $teamClass::factory()->create()->id,
        'status' => 'STATUS_SCHEDULED', 'odds_data' => null,
    ]);
}

function collegeReadinessForecast(string $sport, Model $game, array $configuration = [], array $homeOverrides = [], string $publicationState = 'published'): CanonicalPrediction
{
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame',
        'configuration' => ['inputs' => ['use_previous_season_metrics_fallback' => true], ...$configuration]]);
    $side = fn (int $teamId): array => ['team_id' => $teamId, 'metrics' => ['record_season' => 2026,
        'offensive_efficiency' => 100, 'defensive_efficiency' => 100, 'tempo' => 70]];
    $inputs = ['event' => ['season' => 2027], 'home' => array_replace_recursive($side($game->home_team_id), $homeOverrides), 'away' => $side($game->away_team_id)];
    $snapshot = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $game->sport_event_id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'inputs' => $inputs, 'captured_at' => now()]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $game->sport_event_id, 'event_input_snapshot_id' => $snapshot->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);

    return CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $game->sport_event_id,
        'calculation_run_id' => $run->id, 'publication_state' => $publicationState, 'phase' => 'pregame',
        'generated_at' => now(), 'published_at' => $publicationState === 'published' ? now() : null]);
}

it('reports explicit placeholder holds without treating them as missing forecasts or metrics', function (string $sport) {
    $game = collegeReadinessGame($sport, ['school' => 'TBD', 'abbreviation' => 'TBD', 'espn_id' => -100]);
    foreach ([PredictionCompletenessCheck::class, UpcomingGameReadinessCheck::class] as $check) {
        $result = app($check)->run($sport, config('validation.sports.'.$sport));
        expect($result['status'])->toBe('passing')
            ->and($result['metadata']['games_missing_predictions'])->toBe(0)
            ->and($result['metadata']['scheduled_games_considered'])->toBe(1)
            ->and($result['metadata']['expected_forecast_holds'])->toBe(1)
            ->and($result['metadata']['forecast_hold_reasons'])->toBe(['placeholder_teams' => 1])
            ->and($result['metadata']['sample_expected_hold_game_ids'])->toBe([$game->id]);
    }
})->with(['cbb', 'wcbb']);

it('does not hide missing teams or a genuinely missing known-team forecast as an expected hold', function (string $sport, bool $missingTeam) {
    $game = collegeReadinessGame($sport);
    if ($missingTeam) {
        $game->update(['home_team_id' => null]);
    }
    foreach ([PredictionCompletenessCheck::class, UpcomingGameReadinessCheck::class] as $check) {
        $result = app($check)->run($sport, config('validation.sports.'.$sport));
        expect($result['status'])->toBe('failing')
            ->and($result['metadata']['games_missing_predictions'])->toBe(1)
            ->and($result['metadata']['expected_forecast_holds'])->toBe(0);
    }
})->with([['cbb', false], ['wcbb', false], ['cbb', true]]);

it('accepts permitted usable frozen prior-season metrics while retaining their source counts', function (string $sport) {
    $game = collegeReadinessGame($sport);
    collegeReadinessForecast($sport, $game);
    $result = app(UpcomingGameReadinessCheck::class)->run($sport, config('validation.sports.'.$sport));
    expect($result['metadata']['games_missing_predictions'])->toBe(0)
        ->and($result['metadata']['games_missing_team_metrics'])->toBe(0)
        ->and($result['metadata']['games_with_frozen_prior_season_metrics'])->toBe(1)
        ->and($result['metadata']['games_with_frozen_current_season_metrics'])->toBe(0);
})->with(['cbb', 'wcbb']);

it('does not accept unpermitted invalid wrong-team or unpublished frozen metric evidence', function (array $configuration, array $homeOverrides, string $state) {
    $game = collegeReadinessGame('cbb');
    collegeReadinessForecast('cbb', $game, $configuration, $homeOverrides, $state);
    $result = app(UpcomingGameReadinessCheck::class)->run('cbb', config('validation.sports.cbb'));
    expect($result['metadata']['games_missing_team_metrics'])->toBe(1);
})->with([
    'fallback forbidden' => [['inputs' => ['use_previous_season_metrics_fallback' => false]], [], 'published'],
    'wrong team' => [[], ['team_id' => 999999], 'published'],
    'zero efficiency' => [[], ['metrics' => ['offensive_efficiency' => 0]], 'published'],
    'missing metric field' => [[], ['metrics' => ['tempo' => null]], 'published'],
    'future metric season' => [[], ['metrics' => ['record_season' => 2028]], 'published'],
    'draft forecast' => [[], [], 'draft'],
]);

it('keeps unusable WCBB model inputs actionable even when a published forecast exists', function () {
    $game = collegeReadinessGame('wcbb');
    collegeReadinessForecast('wcbb', $game);
    $readiness = Mockery::mock(WcbbPregameInputReadiness::class);
    $readiness->shouldReceive('forGames')->once()->andReturn([$game->id => ['holds' => [['side' => 'home', 'metric_id' => null, 'reason' => 'unusable_source_evidence']], 'metrics' => ['home' => null, 'away' => null]]]);
    app()->instance(WcbbPregameInputReadiness::class, $readiness);
    $result = app(UpcomingGameReadinessCheck::class)->run('wcbb', config('validation.sports.wcbb'));
    expect($result['status'])->toBe('failing')
        ->and($result['metadata']['games_missing_predictions'])->toBe(0)
        ->and($result['metadata']['games_missing_team_metrics'])->toBe(0)
        ->and($result['metadata']['games_with_unusable_team_metrics'])->toBe(1)
        ->and($result['metadata']['sample_games'][0]['reasons'])->toContain('unusable_team_metrics');
});
