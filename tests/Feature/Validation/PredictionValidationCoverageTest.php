<?php

use App\Actions\Validation\Checks\PredictionCompletenessCheck;
use App\Actions\Validation\Checks\UpcomingGameReadinessCheck;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportEvent;
use App\Services\Validation\PredictionValidationCoverage;

beforeEach(fn () => $this->travelTo('2026-11-17 12:00:00'));
afterEach(fn () => $this->travelBack());

function validationCoverageFixture(string $state = 'published', int $ageHours = 0): array
{
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2027, 'starts_at' => now()->addDay()]);
    $game = Game::factory()->create(['season' => 2027, 'season_type' => 2, 'sport_event_id' => $event->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nba', 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => 'nba', 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => now()]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $forecast = CanonicalPrediction::factory()->create(['sport' => 'nba', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'publication_state' => $state, 'phase' => 'pregame',
        'generated_at' => now()->subHours($ageHours), 'published_at' => $state === 'published' ? now()->subHours($ageHours) : null, 'revision' => 1]);

    return [$game, $forecast];
}

it('uses the same prediction authority for completeness and readiness', function (bool $pipeline, bool $reads, string $state, bool $legacy, int $missing) {
    config(['prediction_lifecycle.canonical_pipeline.nba' => $pipeline, 'prediction_lifecycle.canonical_reads.nba' => $reads]);
    [$game] = validationCoverageFixture($state);
    if ($legacy) {
        Prediction::factory()->create(['game_id' => $game->id]);
    }
    $profile = config('validation.sports.nba');
    $complete = app(PredictionCompletenessCheck::class)->run('nba', $profile);
    $ready = app(UpcomingGameReadinessCheck::class)->run('nba', $profile);
    expect($complete['metadata']['games_missing_predictions'])->toBe($missing)
        ->and($ready['metadata']['games_missing_predictions'])->toBe($missing)
        ->and($ready['metadata']['prediction_source'])->toBe($complete['metadata']['prediction_source']);
})->with([
    'canonical reads and writes' => [true, true, 'published', false, 0],
    'pipeline tracking before read cutover' => [true, false, 'published', false, 0],
    'canonical reads without scheduled writes' => [false, true, 'published', false, 0],
    'draft is not coverage' => [true, true, 'draft', true, 1],
    'legacy mode ignores a canonical-only forecast' => [false, false, 'published', false, 1],
    'legacy mode uses legacy predictions' => [false, false, 'draft', true, 0],
]);

it('uses the newest published revision when an older revision is stale', function () {
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    [$game, $old] = validationCoverageFixture('published', 48);
    $inputs = ['home_rating' => 1515.0, 'away_rating' => 1490.0];
    $input = EventInputSnapshot::factory()->create(['sport' => 'nba', 'sport_event_id' => $game->sport_event_id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'inputs' => $inputs,
        'content_hash' => hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR))]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $game->sport_event_id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $old->calculationRun->calculation_release_id, 'status' => 'succeeded']);
    $new = CanonicalPrediction::factory()->create(['sport' => 'nba', 'sport_event_id' => $game->sport_event_id,
        'calculation_run_id' => $run->id, 'publication_state' => 'published', 'phase' => 'pregame',
        'generated_at' => now(), 'published_at' => now(), 'revision' => 2]);
    expect(app(PredictionValidationCoverage::class)->forGames('nba', collect([$game]))->get($game->sport_event_id)->id)->toBe($new->id);
    $result = app(PredictionCompletenessCheck::class)->run('nba', config('validation.sports.nba'));
    expect($result['metadata']['games_with_stale_predictions'])->toBe(0);
});

it('does not count future-dated canonical forecasts as current coverage', function () {
    config(['prediction_lifecycle.canonical_pipeline.nba' => true]);
    [$game] = validationCoverageFixture('published', -1);
    $profile = config('validation.sports.nba');
    expect(app(PredictionValidationCoverage::class)->forGames('nba', collect([$game])))->toBeEmpty()
        ->and(app(PredictionCompletenessCheck::class)->run('nba', $profile)['metadata']['games_missing_predictions'])->toBe(1)
        ->and(app(UpcomingGameReadinessCheck::class)->run('nba', $profile)['metadata']['games_missing_predictions'])->toBe(1);
});
