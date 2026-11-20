<?php

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Notifications\BettingValueAlertFormatter;
use App\Services\MLB\Picks\MlbPlayerPropCandidateBuilder;
use App\Services\Predictions\BettingForecastSource;
use App\Services\Predictions\PredictionDeliverySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('filters digest status and kickoff before hydration and applies its cap after exact eligibility', function () {
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    $now = CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC');
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $make = function (string $date, ?string $time, string $status = 'STATUS_SCHEDULED') use ($home, $away): Prediction {
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => $date, 'game_time' => $time ?? '', 'status' => $status]);

        return Prediction::factory()->create(['game_id' => $game->id]);
    };
    $make('2026-11-19', '11:59:00');
    $make('2026-11-19', '12:00:00');
    $make('2026-11-19', '13:00:00', 'STATUS_IN_PROGRESS');
    $make('2026-11-19', '13:00:00', 'STATUS_FINAL');
    $make('2026-11-19', null);
    // The separate clock overrides an embedded timestamp in the PHP contract.
    $make('2026-11-19 13:00:00', '11:00:00');
    $eligible = collect(range(1, 35))->map(fn () => $make('2026-11-19', '15:00:00'));
    $retrieved = 0;
    Prediction::retrieved(function () use (&$retrieved) {
        $retrieved++;
    });

    $rows = app(PredictionDeliverySource::class)->forDigest('nba', $now);

    expect($rows->pluck('source.id')->all())->toBe($eligible->take(30)->pluck('id')->all())
        // Each forecast is also loaded through game.prediction for legacy calculators.
        ->and($retrieved)->toBe(72);
});

it('retains embedded timestamps and separate-clock overrides in legacy digest selection', function () {
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $make = function (string $date, ?string $time) use ($home, $away) {
        $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => $date, 'game_time' => $time ?? '', 'status' => 'STATUS_DELAYED']);

        return Prediction::factory()->create(['game_id' => $game->id]);
    };
    $embedded = $make('2026-11-19 15:00:00', null);
    $override = $make('2026-11-19 10:00:00', '16:00:00');

    expect(app(PredictionDeliverySource::class)->forDigest('nba', CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC'))->pluck('source.id')->all())
        ->toBe([$embedded->id, $override->id]);
});

it('does not resolve a batch-selected missing forecast again for MLB props', function () {
    $forecasts = Mockery::mock(BettingForecastSource::class);
    $forecasts->shouldNotReceive('forGames');
    app()->instance(BettingForecastSource::class, $forecasts);
    $home = App\Models\MLB\Team::factory()->create();
    $away = App\Models\MLB\Team::factory()->create();
    $game = App\Models\MLB\Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);

    $game->load('playerProps');
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(MlbPlayerPropCandidateBuilder::class)->buildForForecast($game, null))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('formats an explicit delivery context without re-resolving its forecast identity', function () {
    $source = Mockery::mock(PredictionDeliverySource::class);
    $source->shouldNotReceive('context');
    $game = new Game(['game_date' => '2026-11-19', 'game_time' => '15:00:00']);
    $game->id = 123;
    $game->setRelation('homeTeam', new Team(['name' => 'Home']))->setRelation('awayTeam', new Team(['name' => 'Away']));
    $context = new PredictionDeliveryContext(new Prediction, 'nba', $game, 2.0, 220.0, 0.6, 75);
    $formatter = new BettingValueAlertFormatter($source);

    expect($formatter->toArray((object) [], $context, 'nba', 5.0, 'Home ML'))->toMatchArray(['game_id' => 123, 'confidence' => 75, 'home_team' => 'Home'])
        ->and(fn () => $formatter->toArray((object) [], $context, 'nfl', 5.0, 'Home ML'))->toThrow(InvalidArgumentException::class);
});
