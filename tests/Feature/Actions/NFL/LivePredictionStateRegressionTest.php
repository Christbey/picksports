<?php

use App\Actions\NFL\CalculateLiveWinProbability;
use App\Actions\NFL\LiveGameState;
use App\Actions\NFL\UpdateLivePrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_IN_PROGRESS',
        'season_type' => 'regular',
        'period' => 4,
        'game_clock' => '0:01',
        'home_score' => 24,
        'away_score' => 21,
    ]);
    $this->prediction = Prediction::factory()->create([
        'game_id' => $this->game->id,
        'win_probability' => 0.5,
        'predicted_spread' => 0,
        'predicted_total' => 45,
        'live_win_probability' => 0.8,
        'live_predicted_total' => 50,
        'live_predicted_spread' => 5,
        'live_seconds_remaining' => 100,
        'live_updated_at' => now()->subMinute(),
    ]);
    $this->game->load('prediction');
});

test('NFL rejects invalid live states rather than interpreting unknown as zero', function (array $changes) {
    $this->game->forceFill($changes);

    expect(app(CalculateLiveWinProbability::class)->execute($this->game))->toBeNull()
        ->and(app(UpdateLivePrediction::class)->preview($this->game))->toBeNull()
        ->and(app(UpdateLivePrediction::class)->execute($this->game))->toBeNull();

    $this->prediction->refresh();
    expect($this->prediction->live_win_probability)->toBeNull()
        ->and($this->prediction->live_seconds_remaining)->toBeNull()
        ->and($this->prediction->live_updated_at)->toBeNull();
})->with([
    'missing clock' => [['game_clock' => null]],
    'blank clock' => [['game_clock' => '']],
    'non-numeric clock' => [['game_clock' => 'ab:cd']],
    'invalid seconds' => [['game_clock' => '2:99']],
    'clock beyond quarter' => [['game_clock' => '15:01']],
    'negative clock' => [['game_clock' => '-1:00']],
    'trailing clock junk' => [['game_clock' => '1:00 extra']],
    'missing period' => [['period' => null]],
    'pregame period' => [['period' => 0]],
    'missing home score' => [['home_score' => null]],
    'missing away score' => [['away_score' => null]],
    'negative score' => [['away_score' => -1]],
    'regular season cannot have second OT' => [['period' => 6]],
    'regular season OT beyond 10 minutes' => [['period' => 5, 'game_clock' => '10:01']],
]);

test('NFL preview reads an already loaded snapshot without queries or writes', function () {
    $before = $this->game->prediction->getAttributes();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $preview = app(UpdateLivePrediction::class)->preview($this->game);

    expect($preview)->not->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty()
        ->and($this->game->prediction->getAttributes())->toBe($before);
    DB::disableQueryLog();
});

test('NFL live actions use identical probabilities and clocks', function (array $changes, int $remaining) {
    $this->game->forceFill($changes);
    $preview = app(UpdateLivePrediction::class)->preview($this->game);
    $calculator = app(CalculateLiveWinProbability::class)->execute($this->game);
    $persisted = app(UpdateLivePrediction::class)->execute($this->game);

    expect($preview)->toBe($persisted)
        ->and($preview['live_win_probability'])->toBe($calculator['home_win_probability'])
        ->and($preview['live_seconds_remaining'])->toBe($remaining)
        ->and($calculator['seconds_remaining'])->toBe($remaining);
})->with([
    'regulation start' => [['period' => 1, 'game_clock' => '15:00'], 3600],
    'halftime' => [['period' => 2, 'game_clock' => '0:00', 'status' => 'STATUS_HALFTIME'], 1800],
    'quarter transition' => [['period' => 3, 'game_clock' => '15:00'], 1800],
    'regular OT' => [['period' => 5, 'game_clock' => '10:00'], 600],
    'postseason OT' => [['season_type' => 'postseason', 'period' => 5, 'game_clock' => '15:00'], 900],
    'second postseason OT' => [['season_type' => 'postseason', 'period' => 6, 'game_clock' => '15:00'], 900],
]);

test('NFL live zero clock does not turn an unresolved play into a settled outcome', function () {
    $action = app(UpdateLivePrediction::class);
    $oneSecond = $action->preview($this->game)['live_win_probability'];
    $this->game->game_clock = '0:00';
    $zeroSeconds = $action->preview($this->game)['live_win_probability'];

    expect($oneSecond)->toBeGreaterThan(0.9)
        ->and($zeroSeconds)->toBeLessThan(0.99)
        ->and(abs($oneSecond - $zeroSeconds))->toBeLessThan(0.01);

    $this->game->status = 'STATUS_FINAL';
    expect($action->preview($this->game))->toBeNull();
});

test('NFL heuristic retains kickoff prior and has symmetric bounded monotonic behavior', function () {
    expect(LiveGameState::homeProbability(0, 3600, 0.7))->toEqualWithDelta(0.7, 0.000001);
    $previous = 0.0;
    foreach ([3600, 1800, 300, 60, 1, 0] as $remaining) {
        $home = LiveGameState::homeProbability(3, $remaining, 0.5);
        $away = LiveGameState::homeProbability(-3, $remaining, 0.5);
        expect($home)->toBeGreaterThanOrEqual($previous)
            ->and($home + $away)->toEqualWithDelta(1.0, 0.000001)
            ->and($home)->toBeLessThanOrEqual(0.99);
        $previous = $home;
    }
});

test('NFL tied overtime does not reintroduce a pregame advantage when its clock resets', function () {
    expect(LiveGameState::homeProbability(0, 900, 0.9, true))->toBe(0.5)
        ->and(LiveGameState::homeProbability(0, 0, 0.9, true))->toBe(0.5);
});

test('NFL live projections preserve genuine score zeroes and cannot project fewer scored points', function () {
    $this->game->forceFill(['period' => 1, 'game_clock' => '15:00', 'home_score' => 0, 'away_score' => 0]);
    expect(app(UpdateLivePrediction::class)->preview($this->game)['live_predicted_total'])->toBe(45.0);

    $this->game->forceFill(['period' => 4, 'game_clock' => '0:01', 'home_score' => 70, 'away_score' => 42]);
    expect(app(UpdateLivePrediction::class)->preview($this->game)['live_predicted_total'])->toBeGreaterThanOrEqual(112.0);
});
