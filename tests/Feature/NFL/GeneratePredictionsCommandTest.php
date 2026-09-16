<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\BetDecision;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\NFL\NflPredictionDispositionRecorder;
use App\Services\NFL\NflReleasedBetDecisionRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Mockery as m;

uses()->group('nfl', 'predictions');

afterEach(function () {
    $this->travelBack();
    m::close();
});

it('continues the slate after a game calculation fails and reports the run failed', function () {
    config()->set('nfl.predictions.true_epa.enabled', false);
    $games = Game::factory()->count(2)->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026,
        'season_type' => '2',
        'game_date' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
    ]);
    $generator = m::mock(GeneratePredictionFromHistoricalElo::class);
    $generator->shouldReceive('execute')->once()->ordered()
        ->with(m::on(fn (Game $game): bool => $game->is($games[0])))
        ->andThrow(new RuntimeException('source unavailable'));
    $generator->shouldReceive('execute')->once()->ordered()
        ->with(m::on(fn (Game $game): bool => $game->is($games[1])))
        ->andReturn('created');
    app()->instance(GeneratePredictionFromHistoricalElo::class, $generator);

    $this->artisan('nfl:generate-predictions', ['--season' => 2026])
        ->expectsOutputToContain('1 created, 0 updated')
        ->expectsOutputToContain('Prediction generation failed for game IDs: '.$games[0]->id)
        ->assertFailed();
});

it('isolates decision recording failures and still processes the next game', function (string $failingRecorder) {
    config()->set('nfl.predictions.true_epa.enabled', false);
    $games = Game::factory()->count(2)->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
    ]);
    foreach ($games as $game) {
        Prediction::factory()->create(['game_id' => $game->id]);
    }
    $generator = m::mock(GeneratePredictionFromHistoricalElo::class);
    $generator->shouldReceive('execute')->twice()->andReturn('updated');
    app()->instance(GeneratePredictionFromHistoricalElo::class, $generator);
    $released = m::mock(NflReleasedBetDecisionRecorder::class);
    $dispositions = m::mock(NflPredictionDispositionRecorder::class);
    $coverage = ['candidate_market_keys' => [], 'missing_market_keys' => [], 'hold_reasons' => []];
    if ($failingRecorder === 'released') {
        $released->shouldReceive('recordWithCoverage')->once()->ordered()
            ->with(m::on(fn (Prediction $prediction): bool => $prediction->game_id === $games[0]->id))
            ->andThrow(new RuntimeException('decision storage unavailable'));
        $released->shouldReceive('recordWithCoverage')->once()->ordered()
            ->with(m::on(fn (Prediction $prediction): bool => $prediction->game_id === $games[1]->id))
            ->andReturn($coverage);
        $dispositions->shouldReceive('record')->once()->andReturn(collect());
    } else {
        $released->shouldReceive('recordWithCoverage')->twice()->andReturn($coverage);
        $dispositions->shouldReceive('record')->once()->ordered()
            ->with(m::on(fn (Prediction $prediction): bool => $prediction->game_id === $games[0]->id), [])
            ->andThrow(new RuntimeException('disposition storage unavailable'));
        $dispositions->shouldReceive('record')->once()->ordered()
            ->with(m::on(fn (Prediction $prediction): bool => $prediction->game_id === $games[1]->id), [])
            ->andReturn(collect());
    }
    app()->instance(NflReleasedBetDecisionRecorder::class, $released);
    app()->instance(NflPredictionDispositionRecorder::class, $dispositions);

    $this->artisan('nfl:generate-predictions', ['--season' => 2026])
        ->expectsOutputToContain('0 created, 2 updated')
        ->expectsOutputToContain('Prediction generation failed for game IDs: '.$games[0]->id)
        ->assertFailed();
})->with(['released', 'disposition']);

it('includes Sunday night games stored on the next UTC date in a local date window', function () {
    $this->travelTo('2026-09-06 20:00:00');

    $homeTeam = Team::factory()->create(['abbreviation' => 'NYG']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'DAL']);
    $sundayNight = Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-09-14',
        'game_time' => '00:20:00',
        'status' => 'STATUS_SCHEDULED',
    ]);
    Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
        'game_date' => '2026-09-15',
        'game_time' => '00:15:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    $generator = m::mock(GeneratePredictionFromHistoricalElo::class);
    $generator->shouldReceive('execute')
        ->once()
        ->with(m::on(fn (Game $game): bool => $game->is($sundayNight)))
        ->andReturn('updated');
    $this->app->instance(GeneratePredictionFromHistoricalElo::class, $generator);

    $this->artisan('nfl:generate-predictions', [
        '--season' => 2026,
        '--from-date' => '2026-09-06',
        '--to-date' => '2026-09-13',
    ])
        ->expectsOutput('Generating predictions for 1 games...')
        ->expectsOutput('Prediction generation complete! 0 created, 1 updated.')
        ->assertExitCode(0);
});

it('persists an explicit EPA hold report and returns failure when EPA remains unavailable', function () {
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', false);
    $homeTeam = Team::factory()->create(['abbreviation' => 'CHI']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'DET']);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
    ]);

    $generator = m::mock(GeneratePredictionFromHistoricalElo::class);
    $generator->shouldReceive('execute')
        ->once()
        ->with(m::on(fn (Game $candidate): bool => $candidate->is($game)))
        ->andReturnUsing(function () use ($game): string {
            Prediction::factory()->create([
                'game_id' => $game->getKey(),
                'model_metadata' => [
                    'true_epa' => [
                        'enabled' => true,
                        'applied' => false,
                        'reason' => 'missing_net_true_epa',
                    ],
                    'analysis_layer' => [
                        'eligibility' => [
                            'eligible' => false,
                            'status' => 'hold',
                            'data_reasons' => ['missing_true_epa'],
                        ],
                    ],
                ],
            ]);

            return 'created';
        });
    $this->app->instance(GeneratePredictionFromHistoricalElo::class, $generator);

    $this->artisan('nfl:generate-predictions', ['--season' => 2026])
        ->expectsOutputToContain('1 prediction(s) have unavailable true EPA')
        ->assertFailed();

    expect(data_get(
        Prediction::query()->where('game_id', $game->getKey())->sole()->model_metadata,
        'analysis_layer.eligibility.status',
    ))->toBe('hold');
});

it('fails generation and persists a no-bet hold when an official candidate lacks an exact quote', function () {
    config()->set('nfl.predictions.true_epa.enabled', true);
    config()->set('nfl.predictions.true_epa.backfill_before_generation', false);
    $homeTeam = Team::factory()->create(['abbreviation' => 'CHI']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'DET']);
    $event = SportEvent::factory()->create([
        'sport' => 'nfl',
        'season' => 2026,
        'season_type' => '2',
        'starts_at' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
    ]);
    $game = Game::factory()->create([
        'sport_event_id' => $event->getKey(),
        'season' => 2026,
        'season_type' => 2,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => now()->addDay(),
        'status' => 'STATUS_SCHEDULED',
    ]);

    $generator = m::mock(GeneratePredictionFromHistoricalElo::class);
    $generator->shouldReceive('execute')
        ->once()
        ->with(m::on(fn (Game $candidate): bool => $candidate->is($game)))
        ->andReturnUsing(function () use ($game): string {
            $prediction = Prediction::factory()->create([
                'game_id' => $game->getKey(),
                'predicted_spread' => 7.0,
                'model_metadata' => [
                    'true_epa' => ['enabled' => true, 'applied' => true],
                    'analysis_layer' => [
                        'applied' => true,
                        'bet_classification' => 'bet',
                        'eligibility' => ['eligible' => true, 'status' => 'candidate'],
                        'calculated_edge' => ['market_spread' => 3.0, 'spread_points' => 4.0],
                        'pro_signal_layer' => [
                            'tier' => 'official_candidate',
                            'recommended_markets' => [[
                                'market' => 'spread',
                                'score' => 80,
                                'tier' => 'official_candidate',
                            ]],
                        ],
                    ],
                ],
            ]);
            app(PredictionFeatureSnapshotRecorder::class)->record(
                $prediction,
                $game,
                'nfl',
                [
                    'predicted_spread' => 7.0,
                    'model_metadata' => $prediction->model_metadata,
                ],
                [
                    'run_type' => 'pregame_prediction',
                    'pregame_safe' => true,
                    'availability_status' => 'observed_pregame',
                    'generated_at' => now()->subSecond(),
                    'features_available_at' => now()->subSecond(),
                ],
            );

            return 'created';
        });
    $this->app->instance(GeneratePredictionFromHistoricalElo::class, $generator);

    $this->artisan('nfl:generate-predictions', ['--season' => 2026])
        ->expectsOutputToContain('official candidate that was explicitly held')
        ->assertFailed();

    $hold = BetDecision::query()->where('status', 'held_candidate')->sole();
    expect($hold->status)->toBe('held_candidate')
        ->and($hold->is_bet)->toBeFalse()
        ->and(data_get($hold->explanation, 'hold_reason'))->toBe('exact_fresh_paired_quote_missing')
        ->and(PredictionFeatureSnapshot::query()->count())->toBe(1);
});
