<?php

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\MLB\UpdateLivePrediction;
use App\Models\LivePredictionSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;
use App\Services\Predictions\LivePredictionStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Synthetic observed live state through actual ingestion and storage boundaries.
beforeEach(function () {
    $this->travelTo('2026-04-03 18:00:00');
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    Http::preventStrayRequests();
    Queue::fake();
});
afterEach(fn () => $this->travelBack());

it('admits only observed MLB scoreboard scores and compares supported inning dialects through the real store', function (?string $homeScore, ?string $awayScore, ?string $clock, ?int $inning, ?string $normalizedHalf, bool $admitted) {
    $home = Team::factory()->create(['espn_id' => '99301']);
    $away = Team::factory()->create(['espn_id' => '99302']);
    $game = Game::factory()->create(['espn_event_id' => '409993001', 'season' => 2026,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-04-03', 'game_time' => '17:00:00', 'home_score' => null, 'away_score' => null,
        'inning' => null, 'inning_half' => null]);
    $prediction = Prediction::query()->create(['game_id' => $game->id,
        'predicted_spread' => 1.0, 'predicted_total' => 8.5, 'win_probability' => .58]);
    $pregameBefore = $prediction->fresh()->getAttributes();
    $competitors = [['homeAway' => 'home', 'team' => ['id' => '99301']], ['homeAway' => 'away', 'team' => ['id' => '99302']]];
    foreach ([0 => $homeScore, 1 => $awayScore] as $side => $score) {
        if ($score !== null) {
            $competitors[$side]['score'] = $score;
        }
    }
    $status = ['type' => ['name' => 'STATUS_IN_PROGRESS']];
    if ($clock !== null) {
        $status['displayClock'] = $clock;
    }
    if ($inning !== null) {
        $status['period'] = $inning;
    }
    $event = ['id' => '409993001', 'date' => '2026-04-03T17:00:00Z', 'season' => ['year' => 2026, 'type' => 2],
        'status' => $status, 'competitions' => [['id' => '409993001', 'status' => $status, 'competitors' => $competitors]]];
    $provider = Mockery::mock(EspnService::class);
    $provider->shouldReceive('getScoreboard')->once()->with('20260403')->andReturn(['events' => [$event]]);
    $provider->shouldNotReceive('getGame');
    $count = (new SyncGamesFromScoreboard($provider))->execute('20260403');
    $game = $game->fresh();
    $raw = $game->getAttributes();
    $store = app(LivePredictionStore::class);
    $actual = $store->fields($game);
    $snapshots = LivePredictionSnapshot::query()->where('sport', 'mlb')->where('game_id', $game->id)->get()->map->getAttributes()->all();
    $reference = null;
    if ($admitted && $normalizedHalf !== null) {
        $comparisonGame = clone $game;
        $comparisonGame->forceFill(['inning_half' => $normalizedHalf]);
        $reference = app(UpdateLivePrediction::class)->execute($comparisonGame);
    }
    $trace = getenv('REVIEW_MLB_LIVE_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['input' => [$homeScore, $awayScore, $clock, $inning],
            'synced' => $count, 'raw' => $raw, 'actual_store_fields' => $actual,
            'normalized_reference' => $reference, 'snapshots_before_reference' => $snapshots], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }

    expect($count)->toBe(1)->and($game->home_score)->toBe($homeScore === null ? null : (int) $homeScore)
        ->and($game->away_score)->toBe($awayScore === null ? null : (int) $awayScore)
        ->and($game->inning_half)->toBe($clock)->and($prediction->fresh()->getAttributes())->toBe($pregameBefore);
    if (! $admitted) {
        foreach (['live_predicted_spread', 'live_predicted_total', 'live_win_probability', 'live_outs_remaining'] as $field) {
            expect($actual[$field])->toBeNull();
        }
    } else {
        expect($reference)->toBeArray();
        foreach ($reference as $field => $value) {
            expect($actual[$field])->toBe($value);
        }
    }
    expect($game->fresh()->getAttributes())->toBe($raw);
    Http::assertNothingSent();
})->with([
    'missing home' => [null, '2', 'Top 5th', 5, null, false],
    'missing away' => ['3', null, 'Top 5th', 5, null, false],
    'missing both scores' => [null, null, 'Top 5th', 5, null, false],
    'missing state' => ['3', '2', null, 5, null, false],
    'empty state' => ['3', '2', '', 5, null, false],
    'malformed state' => ['3', '2', 'unavailable', 5, null, false],
    'missing inning' => ['3', '2', 'Top 5th', null, null, false],
    'producer Top 5th equals normalized top' => ['3', '2', 'Top 5th', 5, 'top', true],
    'producer Bottom 5th equals normalized bottom' => ['3', '2', 'Bottom 5th', 5, 'bottom', true],
    'real zero start' => ['0', '0', 'Top 1st', 1, 'top', true],
    'extra top' => ['3', '3', 'Top 10th', 10, 'top', true],
    'extra bottom' => ['3', '3', 'Bottom 10th', 10, 'bottom', true],
    'normalized top baseline positive' => ['3', '2', 'top', 5, 'top', true],
    'normalized bottom baseline positive' => ['3', '2', 'bottom', 5, 'bottom', true],
    'normalized zero baseline positive' => ['0', '0', 'top', 1, 'top', true],
    'producer Bot ordinal equals normalized bottom' => ['3', '2', 'Bot 5th', 5, 'bottom', true],
]);

it('withholds invalid current MLB attributes and retains an earlier real live snapshot', function (array $changes) {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['season' => 2026, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_IN_PROGRESS', 'inning' => 5, 'inning_half' => 'top', 'home_score' => 3, 'away_score' => 2]);
    Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => 1.0, 'predicted_total' => 8.5, 'win_probability' => .58]);
    $action = app(UpdateLivePrediction::class);
    $original = $action->execute($game);
    expect($original)->toBeArray();
    $snapshot = LivePredictionSnapshot::query()->where('sport', 'mlb')->where('game_id', $game->id)->sole();
    $storedGame = $game->fresh()->getAttributes();
    $game->forceFill($changes);
    expect($game->isDirty())->toBeTrue();
    $actual = $action->execute($game);
    $fields = app(LivePredictionStore::class)->fields($game);
    $trace = getenv('REVIEW_MLB_LIVE_TRACE');
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['kind' => 'warm-current', 'changes' => $changes,
            'raw' => $game->getAttributes(), 'original' => $original, 'actual' => $actual,
            'fields' => $fields, 'history_projection' => $snapshot->fresh()->projection], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
    expect($actual)->toBeNull();
    foreach (['live_predicted_spread', 'live_predicted_total', 'live_win_probability', 'live_outs_remaining'] as $field) {
        expect($fields[$field])->toBeNull();
    }
    expect($snapshot->fresh()->projection)->toEqual($original)
        ->and($game->fresh()->getAttributes())->toBe($storedGame);
    Http::assertNothingSent();
})->with([
    'missing current score' => [['home_score' => null]],
    'negative current score' => [['away_score' => -1]],
    'fractional current score' => [['home_score' => 1.5]],
    'fractional current inning' => [['inning' => 5.5]],
    'missing current half' => [['inning_half' => null]],
    'unknown current half' => [['inning_half' => 'unavailable']],
]);
