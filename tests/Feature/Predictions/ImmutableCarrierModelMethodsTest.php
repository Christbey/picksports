<?php

use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\ResultRatingRun;
use App\Models\CFB\Team;
use App\Models\DatasetExportManifest;

it('keeps frozen evidence unchanged through ordinary quiet and numeric model methods', function (string $kind, string $method) {
    if ($kind === 'live') {
        $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
        $model = LivePredictionSnapshot::query()->create([
            'game_id' => $game->id, 'prediction_id' => 10, 'source' => 'test', 'state_hash' => hash('sha256', 'live'),
            'pregame' => [], 'state' => [], 'projection' => [], 'markets' => [], 'props' => [], 'status' => 'stored', 'observed_at' => now(),
        ]);
        $field = 'prediction_id';
    } elseif ($kind === 'canonical_live') {
        $model = App\Models\LivePredictionSnapshot::query()->create([
            'sport' => 'nba', 'game_table' => 'nba_games', 'game_id' => 10,
            'baseline' => [], 'state' => [], 'projection' => [], 'status' => 'stored',
            'calculator' => 'test', 'state_hash' => hash('sha256', 'canonical-live'), 'observed_at' => now(),
        ]);
        $field = 'game_id';
    } elseif ($kind === 'rating') {
        $model = ResultRatingRun::query()->create([
            'season' => 2026, 'version' => 'test', 'input_hash' => hash('sha256', 'rating'), 'source_rows' => [], 'model' => [],
            'source_available_at' => now(), 'created_at' => now(),
        ]);
        $field = 'season';
    } else {
        $model = DatasetExportManifest::factory()->create();
        $field = 'row_count';
    }
    $model->refresh();
    $before = $model->getRawOriginal();
    $mutation = function () use ($model, $field, $method): void {
        if (in_array($method, ['save', 'saveQuietly'], true)) {
            $model->setAttribute($field, (int) $model->getAttribute($field) + 1);
            $model->{$method}();
        } elseif (in_array($method, ['delete', 'deleteQuietly'], true)) {
            $model->{$method}();
        } else {
            $model->{$method}($field);
        }
    };
    expect($mutation)->toThrow(LogicException::class);
    expect($model->newQuery()->findOrFail($model->getKey())->getRawOriginal())->toBe($before);
})->with(function () {
    foreach (['live', 'rating', 'manifest', 'canonical_live'] as $kind) {
        foreach (['save', 'delete', 'saveQuietly', 'deleteQuietly', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly'] as $method) {
            yield $kind.' '.$method => [$kind, $method];
        }
    }
});
