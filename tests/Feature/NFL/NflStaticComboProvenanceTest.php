<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Http\Resources\DashboardPredictionResource;
use App\Http\Resources\NFL\PredictionResource;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\User;
use App\Services\NFL\NflOddsHistory;
use App\Services\NFL\NflProSignalLayer;
use App\Support\NflBetRuleEngine;
use App\Support\NflReasonCodeCatalog;
use App\Support\NflValidatedSignalCombos;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

function staticComboEvidenceFixture(): array
{
    return ['name' => 'qb_form_pressure_mismatch', 'label' => 'QB Form + Pressure Mismatch',
        'market' => 'winner', 'tier' => 'strong', 'sample_size' => 127, 'winner_hit_rate' => 73.2,
        'spread_mae' => 10.10, 'codes' => ['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'],
        'note' => 'Large-sample winner signal.'];
}

it('matches historical configuration without presenting it as validated current statistics', function () {
    $matches = app(NflValidatedSignalCombos::class)->match(['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense']);
    expect($matches)->not->toBeEmpty()
        ->and($matches[0]['winner_hit_rate'])->toBeNull()
        ->and($matches[0]['spread_mae'])->toBeNull()
        ->and($matches[0]['sample_size'])->toBe(0)
        ->and($matches[0]['validation_status'])->toBe('historical_unverified');
    expect(config('nfl.predictions.validated_signal_combos.1.winner_hit_rate'))->toBe(73.2);
});

it('does not strengthen a no-edge forecast solely from a historical configuration match', function () {
    config(['nfl.predictions.analysis_layer.enabled' => true, 'nfl.predictions.automated_calibration_tweaks.enabled' => false]);
    $game = new Game;
    $codes = ['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'];
    $rules = Mockery::mock(NflBetRuleEngine::class);
    $rules->shouldReceive('evaluate')->once()->andReturn(['action' => 'none']);
    app()->instance(NflBetRuleEngine::class, $rules);
    $pro = Mockery::mock(NflProSignalLayer::class);
    $pro->shouldReceive('build')->once()->andReturn(['reason_codes' => [], 'risk_flags' => []]);
    $action = Mockery::mock(GeneratePredictionFromHistoricalElo::class)->makePartial()->shouldAllowMockingProtectedMethods();
    (new ReflectionProperty($action, 'proSignalLayer'))->setValue($action, $pro);
    (new ReflectionProperty($action, 'reasonCodeCatalog'))->setValue($action, app(NflReasonCodeCatalog::class));
    $action->shouldReceive('extractMarketSpreadAndTotalFromGame')->once()->andReturn([3.0, 45.0]);
    $action->shouldReceive('analysisRiskFlags')->once()->andReturn([]);
    $action->shouldReceive('analysisTrustScore')->once()->andReturn(55.0);
    $action->shouldReceive('betClassification')->once()->andReturn('no_bet_no_edge');
    $action->shouldReceive('modelSignalClassification')->once()->andReturn('moderate_model_side');
    $action->shouldReceive('analysisReasonCodes')->once()->andReturn($codes);
    $action->shouldReceive('oddsHistory')->once()->andReturn(new NflOddsHistory($game));
    (new ReflectionMethod($action, 'applyAnalysisLayer'))->invoke($action, $game, 3.0, 45.0, 0.55);
    $metadata = (new ReflectionProperty($action, 'lastModelMetadata'))->getValue($action);
    expect($metadata['analysis_layer']['bet_classification'])->toBe('no_bet_no_edge')
        ->and($metadata['analysis_layer']['validated_signals'])->not->toBeEmpty();
});

it('sanitizes old persisted combo claims in both prepared and dashboard resources without changing storage', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id, 'model_metadata' => [
        'analysis_layer' => ['applied' => true, 'bet_classification' => 'validated_winner_watchlist',
            'validated_signals' => [staticComboEvidenceFixture()], 'best_validated_signal' => staticComboEvidenceFixture()],
    ]])->load('game');
    $before = $prediction->fresh()->getRawOriginal();
    $request = Request::create('/');
    $user = User::factory()->create();
    Permission::findOrCreate('view-prediction-betting-value', 'web');
    $user->givePermissionTo('view-prediction-betting-value');
    $request->setUserResolver(fn () => $user);
    $prepared = resolvePreparedPredictionResource(PredictionResource::class, $prediction, 'nfl', $request);
    $dashboard = (new DashboardPredictionResource($prediction))->sport('nfl')->bettingValue([])->resolve($request);
    foreach ([$prepared, $dashboard] as $data) {
        expect($data['prediction_analysis']['best_validated_signal']['winner_hit_rate'])->toBeNull()
            ->and($data['prediction_analysis']['validated_signals'][0]['spread_mae'])->toBeNull()
            ->and($data['prediction_analysis']['bet_classification'])->toBe('no_bet_no_edge');
    }
    expect($prediction->fresh()->getRawOriginal())->toBe($before);
});
