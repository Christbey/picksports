<?php

use App\Models\BetDecision;
use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEvent;
use App\Services\MLB\Picks\BetDecisionRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function isolatedPickDecisionFixture(bool $canonical = true): array
{
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'starts_at' => now()->addDay()]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory(),
        'away_team_id' => Team::factory(), 'status' => 'STATUS_SCHEDULED']);
    $legacy = Prediction::create(['game_id' => $game->id, 'season' => 2026, 'season_type' => '2',
        'home_team_elo' => 1500, 'away_team_elo' => 1500, 'home_combined_elo' => 1500, 'away_combined_elo' => 1500,
        'predicted_spread' => 1, 'predicted_total' => 8, 'win_probability' => 0.6, 'confidence_score' => 60]);
    $snapshot = PredictionFeatureSnapshot::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions',
        'prediction_id' => $legacy->id, 'game_id' => $game->id, 'model_version' => 'historical',
        'feature_version' => 'historical', 'blend_version' => 'historical', 'features' => [], 'outputs' => [],
        'generated_at' => now()->subHour()]);
    $native = CanonicalPrediction::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id]);
    $candidate = PickCandidate::create(['season' => 2026, 'game_id' => $game->id, 'prediction_id' => $legacy->id,
        'canonical_prediction_id' => $canonical ? $native->id : null, 'market_type' => 'moneyline', 'market_key' => 'h2h',
        'side' => 'home', 'price' => -110, 'model_probability' => 0.61, 'status' => 'tracking_only',
        'recommendation_label' => 'tracking_only', 'is_public' => false, 'is_tracking_only' => true, 'is_bet' => false,
        'risk_flags' => ['point_in_time_unsafe'], 'reason_codes' => ['model_market_agrees'],
        'feature_snapshot' => ['prediction_lineage' => ['source' => 'canonical', 'prediction_id' => $native->id,
            'calculation_run_id' => 'frozen-run', 'event_input_snapshot_id' => 'frozen-input'],
            'promotion_blocked_reasons' => ['canonical_candidate_requires_validation']],
        'generated_at' => now(), 'game_start_at' => $event->starts_at]);

    return [$candidate, $snapshot, $native];
}

it('never borrows legacy snapshots for a canonical pick even with both identities attached', function (bool $pipeline, bool $reads) {
    config(['prediction_lifecycle.canonical_pipeline.mlb' => $pipeline, 'prediction_lifecycle.canonical_reads.mlb' => $reads]);
    [$candidate, $snapshot, $native] = isolatedPickDecisionFixture();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $decision = app(BetDecisionRecorder::class)->record($candidate);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->not->toContain('prediction_feature_snapshots')->not->toContain('mlb_predictions')
        ->and($decision->prediction_feature_snapshot_id)->toBeNull()
        ->and($decision->model_run_id)->toBeNull()
        ->and($decision->prediction_table)->toBe('predictions')
        ->and($decision->prediction_id)->toBe($native->id)
        ->and($decision->feature_snapshot)->toBe($candidate->feature_snapshot)
        ->and($decision->is_public)->toBeFalse()->and($decision->is_bet)->toBeFalse()
        ->and($decision->is_tracking_only)->toBeTrue()->and($decision->pregame_safe)->toBeFalse()
        ->and($decision->eligibility_reasons)->toContain('point_in_time_unsafe');
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('retains the original snapshot lookup for historical candidates after cutover', function () {
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
    [$candidate, $snapshot] = isolatedPickDecisionFixture(false);
    $decision = app(BetDecisionRecorder::class)->record($candidate);
    expect($decision->prediction_table)->toBe('mlb_predictions')
        ->and($decision->prediction_id)->toBe($candidate->prediction_id)
        ->and($decision->prediction_feature_snapshot_id)->toBe($snapshot->id);
});

it('keeps an existing recorded decision stable and settles from its candidate without legacy reads', function () {
    [$candidate] = isolatedPickDecisionFixture();
    $recorder = app(BetDecisionRecorder::class);
    $original = $recorder->record($candidate)->fresh()->getAttributes();
    $candidate->forceFill(['model_probability' => 0.99, 'graded_at' => now(), 'result_status' => 'win',
        'result_value' => 1, 'result_profit_units' => 0.91])->save();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($recorder->record($candidate)->getAttributes())->toBe($original);
        $settlement = $recorder->settle($candidate);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
    }
    expect(BetDecision::count())->toBe(1)->and($settlement->result_status)->toBe('win')
        ->and((float) $settlement->profit_units)->toBe(0.91)
        ->and($queries)->not->toContain('prediction_feature_snapshots')->not->toContain('mlb_predictions');
});
