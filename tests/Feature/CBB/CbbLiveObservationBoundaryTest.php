<?php

use App\Actions\CBB\UpdateLivePrediction;
use App\Actions\ESPN\CBB\SyncGamesFromScoreboard;
use App\Application\Sports\ReadModels\LivePregameForecast;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\LivePredictionSnapshot;
use App\Services\ESPN\CBB\EspnService;
use App\Services\Predictions\LivePredictionStore;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-01-30 18:00:00');
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_reads.cbb' => false]);
});
afterEach(fn () => $this->travelBack());

function cbbLiveObservationTrace(string $case, array $data): void
{
    $path = getenv('REVIEW_CBB_LIVE_TRACE');
    if (is_string($path) && $path !== '') {
        file_put_contents($path, json_encode(['case' => $case, ...$data], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}

it('admits only observed CBB scoreboard live states through the real store', function (string $case, ?string $home, ?string $away, ?string $clock, int $period, bool $known, int $remaining) {
    $h = Team::factory()->create(['espn_id' => '99101']);
    $a = Team::factory()->create(['espn_id' => '99102']);
    $game = Game::factory()->create(['espn_event_id' => '409991001', 'home_team_id' => $h->id, 'away_team_id' => $a->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'period' => 0,
        'game_date' => '2026-01-30', 'game_time' => '17:00:00', 'home_score' => null, 'away_score' => null]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 4.0, 'predicted_total' => 140.0, 'win_probability' => 0.62]);
    $predictionBefore = $prediction->fresh()->getAttributes();
    $homeCompetitor = ['homeAway' => 'home', 'team' => ['id' => '99101']];
    $awayCompetitor = ['homeAway' => 'away', 'team' => ['id' => '99102']];
    if ($home !== null) {
        $homeCompetitor['score'] = $home;
    }
    if ($away !== null) {
        $awayCompetitor['score'] = $away;
    }
    $status = ['type' => ['name' => 'STATUS_IN_PROGRESS'], 'period' => $period];
    if ($clock !== null) {
        $status['displayClock'] = $clock;
    }
    $event = ['id' => '409991001', 'date' => '2026-01-30T17:00:00Z', 'season' => ['year' => 2026, 'type' => 2],
        'week' => ['number' => 15], 'status' => $status, 'competitions' => [['id' => '409991001', 'neutralSite' => false,
            'competitors' => [$homeCompetitor, $awayCompetitor]]]];
    $provider = Mockery::mock(EspnService::class);
    $provider->shouldReceive('getScoreboard')->once()->with('20260130')->andReturn(['events' => [$event]]);
    $provider->shouldNotReceive('getGame');
    app()->instance(EspnService::class, $provider);
    $count = (new SyncGamesFromScoreboard($provider))->execute('20260130');
    $game = $game->fresh();
    $store = app(LivePredictionStore::class);
    $fields = $store->fields($game);
    $snapshots = LivePredictionSnapshot::query()->where('sport', 'cbb')->where('game_id', $game->id)->get();
    cbbLiveObservationTrace($case, ['synced' => $count, 'raw_state' => array_intersect_key($game->getAttributes(), array_flip(['status', 'period', 'game_clock', 'home_score', 'away_score'])),
        'fields' => $fields, 'snapshots' => $snapshots->map->only(['status', 'state', 'projection'])->all()]);
    expect($count)->toBe(1)->and($game->home_score)->toBe($home === null ? null : (int) $home)
        ->and($game->away_score)->toBe($away === null ? null : (int) $away)
        ->and($prediction->fresh()->getAttributes())->toBe($predictionBefore);
    if (! $known) {
        expect($fields['live_predicted_spread'])->toBeNull()->and($fields['live_win_probability'])->toBeNull()
            ->and($fields['live_predicted_total'])->toBeNull()->and($snapshots->where('status', 'live'))->toHaveCount(0);
    } else {
        expect($fields['live_seconds_remaining'])->toBe($remaining);
        foreach (['live_predicted_spread', 'live_win_probability', 'live_predicted_total'] as $key) {
            expect(is_finite($fields[$key]))->toBeTrue();
        }
        expect($snapshots->where('status', 'live'))->toHaveCount(1);
    }
})->with([
    'missing home observation' => ['missing home', null, '5', '19:00', 1, false, 0],
    'missing away observation' => ['missing away', '5', null, '19:00', 1, false, 0],
    'missing clock observation' => ['missing clock', '5', '4', null, 1, false, 0],
    'blank clock observation' => ['blank clock', '5', '4', '', 1, false, 0],
    'real observed zero start' => ['real zero', '0', '0', '20:00', 1, true, 2400],
    'real end period zero clock' => ['period end', '30', '28', '0:00', 1, true, 1200],
    'real overtime start' => ['overtime', '70', '70', '05:00', 3, true, 300],
    'fractional seconds preserved' => ['fractional seconds', '60', '58', '00:01.5', 2, true, 1],
    'fractional period-end preserved' => ['fractional zero', '60', '58', '0:00.5', 2, true, 0],
]);

it('withholds malformed direct CBB preview input rather than manufacturing elapsed time', function (int $period, ?string $clock, int $home, int $away) {
    $game = new Game(['home_team_id' => 0, 'away_team_id' => 0, 'season' => 2026]);
    $output = app(UpdateLivePrediction::class)->previewState($game, new LivePregameForecast(4.0, 140.0, 0.62, []), $period, $clock, $home, $away);
    cbbLiveObservationTrace('direct-preview', ['input' => [$period, $clock, $home, $away], 'output' => $output]);
    expect($output)->toBeNull();
})->with([
    'null clock' => [1, null, 0, 0], 'seconds overflow' => [1, '19:60', 0, 0],
    'regulation overflow' => [1, '20:01', 0, 0], 'OT overflow' => [3, '05:01', 70, 70],
    'negative period' => [-1, '20:00', 0, 0], 'negative score' => [1, '20:00', -1, 0],
]);

it('refuses current dirty CBB attributes and clears an existing real live projection', function (array $changes) {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026,
        'status' => 'STATUS_IN_PROGRESS', 'period' => 1, 'game_clock' => '20:00', 'home_score' => 0, 'away_score' => 0]);
    Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 4.0, 'predicted_total' => 140.0, 'win_probability' => 0.62]);
    $action = app(UpdateLivePrediction::class);
    $original = $action->execute($game);
    expect($original)->toBeArray();
    $originalSnapshot = LivePredictionSnapshot::query()->where('sport', 'cbb')->where('game_id', $game->id)->sole();
    $game->forceFill($changes);
    expect($game->isDirty())->toBeTrue();
    $result = $action->execute($game);
    $fields = app(LivePredictionStore::class)->fields($game);
    cbbLiveObservationTrace('dirty-current', ['changes' => $changes, 'raw' => $game->getAttributes(),
        'original' => $game->getRawOriginal(), 'before_projection' => $original, 'after_projection' => $result, 'fields' => $fields]);
    expect($result)->toBeNull()->and($fields['live_predicted_spread'])->toBeNull()
        ->and($fields['live_predicted_total'])->toBeNull()->and($fields['live_win_probability'])->toBeNull()
        ->and($originalSnapshot->fresh()->projection)->toEqual($original);
})->with([
    'fractional current period' => [['period' => 1.5]],
    'garbage current period' => [['period' => '1junk']],
    'fractional current home score' => [['home_score' => 1.5]],
    'garbage current away score' => [['away_score' => 'invalid']],
    'missing current score' => [['home_score' => null]],
    'missing current clock' => [['game_clock' => null]],
]);
