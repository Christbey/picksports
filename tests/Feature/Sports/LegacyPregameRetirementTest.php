<?php

use App\Actions\CBB\RecalculateTournamentOutlook;
use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\MLB\GenerateCanonicalPrediction;
use App\Actions\MLB\GeneratePrediction;
use App\Actions\Validation\Checks\PredictionCompletenessCheck;
use App\Jobs\NFL\EvaluateShadowPrediction;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CBB\Prediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\NFL\NflFullHistoricalShadowInferenceService;
use App\Services\NFL\NflPredictionPublisher;
use App\Services\NFL\Simulation\GameSimulationService;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\SportsPipelineRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\ObservedMlbGameFixture;

beforeEach(function () {
    $this->travelTo('2026-10-28 12:00:00');
    Http::preventStrayRequests();
    config(['mlb_ml.mode' => 'shadow', 'mlb_ml.shadow.enabled' => true, 'mlb_ml.period_models.enabled' => true]);
});
afterEach(fn () => $this->travelBack());

function pregameRetirementFixture(string $sport, ?CalculationRelease $release = null): array
{
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $teamClass = $context->models['team'];
    $gameClass = $context->models['game'];
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = $gameClass::factory()->create(['season' => 2026, 'season_type' => '2', 'sport_event_id' => $event->id,
        'status' => 'STATUS_SCHEDULED', 'game_date' => $event->starts_at->toDateString(), 'game_time' => $event->starts_at->format('H:i:s'),
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    if ($game instanceof Game) {
        $game = ObservedMlbGameFixture::observe($game, $event->starts_at);
    }
    $release ??= CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $inputAttributes = ['sport' => $sport, 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => now(),
        'inputs' => ['event' => ['starts_at' => $event->starts_at->toIso8601String()], 'home' => ['team_id' => $game->home_team_id, 'elo' => 1550], 'away' => ['team_id' => $game->away_team_id, 'elo' => 1490],
            'pitching' => ['home' => ['elo' => 1600, 'espn_id' => '100'], 'away' => ['elo' => 1450, 'espn_id' => '200']]]];
    if ($game instanceof Game) {
        $inputAttributes = ObservedMlbGameFixture::snapshotAttributes($game, $inputAttributes['inputs'] ?? []);
    }
    $input = EventInputSnapshot::factory()->create($inputAttributes);
    $run = CalculationRun::factory()->create(['output_hash' => str_repeat('a', 64), 'sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['output_hash' => str_repeat('a', 64), 'sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'publication_state' => 'draft', 'phase' => 'pregame',
        'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.0, null], ['total', 'combined', 9.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $input];
}

it('retires active legacy writes only after both pipeline and readers select canonical', function (string $sport) {
    [$game] = pregameRetirementFixture($sport);
    $source = app(PredictionDeliverySource::class);
    expect($source->legacyWriterRetired($sport))->toBeTrue();
    $writer = 'App\\Actions\\'.strtoupper($sport).'\\'.($sport === 'nfl' ? 'GeneratePredictionFromHistoricalElo' : 'GeneratePrediction');
    expect(fn () => app($writer)->execute($game))->toThrow(LogicException::class, 'Legacy pregame writes are retired');
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    expect($source->legacyWriterRetired($sport))->toBeFalse();
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true, "prediction_lifecycle.canonical_pipeline.{$sport}" => false]);
    expect($source->legacyWriterRetired($sport))->toBeFalse();
})->with(['nba', 'nfl', 'cbb', 'wcbb', 'wnba', 'mlb', 'cfb']);

it('routes the active pipeline to canonical generation and evaluation while retaining historical commands', function (string $sport) {
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $registry = app(SportsPipelineRegistry::class);
    $commands = collect($registry->pipelineSteps($sport, 'predict', $registry->context(date: '2026-10-28', season: 2026)))->pluck('command');
    expect($commands)->toContain("{$sport}:generate-canonical-predictions", "{$sport}:evaluate-canonical-predictions", "{$sport}:calculate-elo")
        ->not->toContain("{$sport}:generate-predictions", "{$sport}:grade-predictions")
        ->and(Artisan::all())->toHaveKey("{$sport}:grade-predictions");
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'mlb', 'cfb']);

it('uses native NFL simulation sources and fits only the same canonical release', function () {
    [$target, $native] = pregameRetirementFixture('nfl');
    $release = $native->calculationRun->release;
    foreach ([10, 6] as $daysAgo) {
        $this->travelTo(now()->subDays($daysAgo));
        [$historical] = pregameRetirementFixture('nfl', $release);
        $this->travelTo('2026-10-28 12:00:00');
        $historical->update(['status' => 'STATUS_FINAL', 'home_score' => 10, 'away_score' => 7,
            'result_updated_at' => now()->subDays(2)]);
        $historical->update(['result_updated_at' => now()->subDays(2)]);
    }
    config(['nfl_game_simulation.minimum_history' => 2, 'nfl_game_simulation.capture_enabled' => true]);
    $service = app(GameSimulationService::class);
    $run = $service->preview($target, iterations: 1000, seed: 12);
    expect($run['status'])->toBe('shadow')->and($run['fitted'])->toBeTrue()
        ->and($run['features']['source_table'])->toBe('predictions')
        ->and($run['features']['history']['pairs'])->toHaveCount(2);
    $captured = $service->capture($target, $run);
    expect($captured->prediction_table)->toBe('predictions')
        ->and($captured->prediction_id)->toBe($native->id)
        ->and($captured->features['source_lineage']['calculation_run_id'])->toBe($native->calculation_run_id)
        ->and($captured->outputs['bet_eligible'])->toBeFalse();
    expect($service->preview($target, basis: 'pre_market')['reason'])->toBe('forecast_basis_unavailable');
});

it('checks canonical prediction coverage without requiring legacy rows', function () {
    [$game, $native] = pregameRetirementFixture('nba');
    $game->update(['season' => 2027]);
    $profile = config('validation.sports.nba');
    $profile['models']['game'] = $game::class;
    $check = app(PredictionCompletenessCheck::class);
    $result = $check->run('nba', $profile);
    expect($result['metadata']['active_games'])->toBe(1)
        ->and($result['metadata']['prediction_source'])->toBe('canonical')
        ->and($result['metadata']['games_missing_predictions'])->toBe(0);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect($check->run('nba', $profile)['metadata']['games_missing_predictions'])->toBe(1);
});

it('skips duplicate legacy morning schedules after cutover while preserving pipeline-only compatibility', function (string $sport) {
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $events = collect(app(Schedule::class)->events());
    if ($sport === 'cbb') {
        $pipeline = $events->firstWhere('description', 'CBB: Run Pregame Pipeline');
        expect($pipeline)->not->toBeNull()->and($pipeline->filtersPass(app()))->toBeTrue();
        config(['prediction_lifecycle.canonical_reads.cbb' => false]);
        expect($pipeline->filtersPass(app()))->toBeTrue();

        return;
    }
    $legacy = $events->first(fn ($event) => str_contains($event->command ?? '', "{$sport}:generate-predictions")
        && ! str_contains($event->description ?? '', 'After Odds'));
    $native = $events->first(fn ($event) => str_contains($event->command ?? '', "{$sport}:generate-canonical-predictions"));
    expect($legacy)->not->toBeNull()->and($native)->not->toBeNull();
    expect($legacy->filtersPass(app()))->toBeFalse()->and($native->filtersPass(app()))->toBeTrue();
    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    expect($legacy->filtersPass(app()))->toBeTrue();
})->with(['nba', 'cbb', 'wcbb', 'wnba', 'mlb']);

it('regenerates native MLB forecasts when a probable pitcher changes', function () {
    [$game, $native] = pregameRetirementFixture('mlb');
    $game->update(['probable_home_pitcher_espn_id' => '100', 'probable_away_pitcher_espn_id' => '200']);
    $this->mock(SyncGamesFromScoreboard::class)->shouldReceive('execute')->twice()
        ->andReturnUsing(function () use ($game) {
            $game->update(['probable_home_pitcher_espn_id' => '300']);

            return 1;
        });
    $this->mock(GenerateCanonicalPrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($actual) => $actual->id === $game->id && $actual->probable_home_pitcher_espn_id === '300')->andReturn($native);
    $this->mock(GeneratePrediction::class)->shouldNotReceive('execute');
    $this->artisan('mlb:refresh-probable-pitchers --days-ahead=1')->expectsOutput('Regenerated predictions for 1 game(s).')->assertSuccessful();
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
});

it('prevents queued legacy NFL shadow jobs and direct publishers from reviving active legacy writes', function () {
    [$game] = pregameRetirementFixture('nfl');
    $this->mock(NflFullHistoricalShadowInferenceService::class)->shouldNotReceive('evaluate');
    app()->call([new EvaluateShadowPrediction(123), 'handle']);
    expect(fn () => app(NflPredictionPublisher::class)->publish($game, [], []))
        ->toThrow(LogicException::class, 'Legacy pregame writes are retired');
});

it('uses selected native probabilities for CBB tournament outlook instead of conflicting legacy rows', function () {
    [$game] = pregameRetirementFixture('cbb');
    $game->update(['season_type' => 3, 'is_ncaa_tournament' => true, 'tournament_region' => 'East',
        'tournament_round' => 'round_of_64', 'home_seed' => 1, 'away_seed' => 16]);
    Prediction::factory()->create(['game_id' => $game->id, 'win_probability' => 0.2]);
    $snapshot = app(RecalculateTournamentOutlook::class)->execute(2026);
    $home = $snapshot->forecasts->firstWhere('team_id', $game->home_team_id);
    $away = $snapshot->forecasts->firstWhere('team_id', $game->away_team_id);
    expect((float) $home->round_of_32_probability)->toBe(0.7)
        ->and((float) $away->round_of_32_probability)->toBe(0.3);
});

it('preserves bounded NFL date ranges when the old command routes to native generation', function () {
    [$included, $native] = pregameRetirementFixture('nfl');
    [$excluded] = pregameRetirementFixture('nfl');
    $excluded->sportEvent->update(['starts_at' => now()->addDays(5)]);
    $excluded->update(['game_date' => now()->addDays(5)->toDateString()]);
    $this->mock(App\Actions\NFL\GenerateCanonicalPrediction::class)->shouldReceive('execute')->once()
        ->withArgs(fn ($actual) => $actual->id === $included->id)->andReturn($native);
    $this->artisan('nfl:generate-predictions', ['--season' => 2026, '--from-date' => now()->toDateString(),
        '--to-date' => now()->addDays(2)->toDateString()])->assertSuccessful();
    expect(PredictionFeatureSnapshot::query()->count())->toBe(0);
});
