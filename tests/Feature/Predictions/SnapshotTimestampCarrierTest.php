<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;

it('retains UTC instants through the actual snapshot recorder and legacy timestamp casts', function () {
    config(['app.timezone' => 'America/Chicago']);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => '2026-09-14', 'game_time' => '18:00:00',
    ]);
    $data = ['game_id' => $game->id, 'predicted_spread' => 1, 'predicted_total' => 220, 'win_probability' => 51, 'confidence_score' => 60];
    $prediction = Prediction::query()->create($data);
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nba', $data, ['generated_at' => '2026-09-14T17:00:00Z'])->fresh();

    expect($snapshot->game_start_at->utc()->toIso8601String())->toBe('2026-09-14T18:00:00+00:00')
        ->and($snapshot->generated_at->utc()->toIso8601String())->toBe('2026-09-14T17:00:00+00:00')
        ->and($snapshot->features_available_at->utc()->toIso8601String())->toBe('2026-09-14T17:00:00+00:00');
});

it('refuses legacy timestamp instants that cannot roundtrip through the application wall clock', function (string $value) {
    config(['app.timezone' => 'America/Chicago']);
    expect(LegacyTimestampCarrier::forInstant(CarbonImmutable::parse($value)))->toBeNull();
})->with([
    'before signed range' => '1969-12-31T23:59:59Z',
    'wall literal before range' => '1970-01-01T00:00:01Z',
    'after signed range' => '2038-01-19T03:14:08Z',
    'ambiguous second fold' => '2026-11-01T07:30:00Z',
]);

it('retains a supported instant through the actual legacy timestamp model cast', function (string $value) {
    config(['app.timezone' => 'America/Chicago']);
    $instant = CarbonImmutable::parse($value);
    $carrier = LegacyTimestampCarrier::forInstant($instant);
    expect($carrier)->not->toBeNull();
    $model = new PredictionFeatureSnapshot(['generated_at' => $carrier]);
    $read = (new PredictionFeatureSnapshot)->setRawAttributes($model->getAttributes(), true);
    expect($read->generated_at->getTimestamp())->toBe($instant->getTimestamp());
})->with([
    'ordinary' => '2026-09-14T17:00:00Z',
    'first fold' => '2026-11-01T06:30:00Z',
    'last supported second' => '2038-01-19T03:14:07Z',
    'declared seconds precision' => '2026-09-14T17:00:00.123456Z',
]);
