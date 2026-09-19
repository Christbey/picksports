<?php

use App\Models\BetDecision;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\EventInputSnapshot;
use App\Models\GameOddsSnapshot;
use App\Models\SportEvent;
use App\Services\CFB\CfbReleasedBetDecisionRecorder;
use App\Services\CFB\Predictions\CfbCanonicalSpreadValueSignalService;

function decisionFixture(): array
{
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(3)]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'status' => 'STATUS_SCHEDULED',
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $snapshot = EventInputSnapshot::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id]);
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'cfb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'published', 'revision' => 1]);
    $odds = GameOddsSnapshot::query()->create(['sport' => 'cfb', 'game_table' => 'cfb_games', 'game_id' => $game->id,
        'captured_at' => now(), 'source' => 'test', 'payload_hash' => hash('sha256', 'test'), 'odds_data' => []]);

    return [$game, $prediction, $snapshot, $odds];
}

it('freezes a prospective decision once with exact price and canonical evidence', function () {
    [$game, $prediction, $snapshot, $odds] = decisionFixture();
    $this->mock(CfbCanonicalSpreadValueSignalService::class)->shouldReceive('forPrediction')->once()->andReturn([
        'has_playable_value' => true, 'spread_assessment' => ['risk_flags' => []],
        'market_confirmation' => ['side' => 'away', 'risk_flags' => [], 'best_quote' => [
            'quote_id' => 88, 'snapshot_id' => $odds->id, 'line' => 20.5, 'price' => -105, 'bookmaker' => 'fanduel',
            'implied_probability' => .512195, 'no_vig_probability' => .49,
        ]], 'best' => ['edge' => 4], 'cover_probability_evidence' => ['cover_probability' => .6, 'expected_value_per_unit' => .1714, 'risk_flags' => []],
    ]);
    $recorder = app(CfbReleasedBetDecisionRecorder::class);
    $decision = $recorder->record($prediction, $game);
    expect($decision->is_bet)->toBeTrue()->and($decision->is_tracking_only)->toBeTrue()
        ->and($decision->price)->toBe(-105)->and($decision->bookmaker)->toBe('fanduel')
        ->and(data_get($decision->feature_snapshot, 'event_input_snapshot_id'))->toBe($snapshot->id)
        ->and(data_get($decision->market_snapshot, 'quote.quote_id'))->toBe(88)
        ->and($recorder->record($prediction, $game)->id)->toBe($decision->id)
        ->and(BetDecision::count())->toBe(1);
    expect(fn () => $decision->update(['price' => -110]))->toThrow(LogicException::class, 'immutable');
    expect(fn () => $decision->fresh()->delete())->toThrow(LogicException::class, 'immutable');
});

it('freezes held decisions and does not invent prices or calibrated probabilities', function () {
    [$game, $prediction] = decisionFixture();
    $this->mock(CfbCanonicalSpreadValueSignalService::class)->shouldReceive('forPrediction')->once()->andReturn([
        'has_playable_value' => false, 'spread_assessment' => ['risk_flags' => ['spread_calibration_unavailable']],
        'market_confirmation' => ['side' => 'home', 'best_quote' => null, 'risk_flags' => ['insufficient_fresh_priced_books']],
        'cover_probability_evidence' => ['cover_probability' => null, 'expected_value_per_unit' => null, 'risk_flags' => ['spread_calibration_unavailable']],
    ]);
    $decision = app(CfbReleasedBetDecisionRecorder::class)->record($prediction, $game);
    expect($decision->is_bet)->toBeFalse()->and($decision->status)->toBe('held_candidate')
        ->and($decision->price)->toBeNull()->and($decision->model_probability)->toBeNull()
        ->and($decision->eligibility_reasons)->toContain('spread_calibration_unavailable');
});

it('refuses retrospective recording of old predictions or started games', function () {
    [$game, $prediction] = decisionFixture();
    $this->travel(16)->minutes();
    expect(app(CfbReleasedBetDecisionRecorder::class)->record($prediction, $game))->toBeNull();
    $this->travel(4)->hours();
    expect(app(CfbReleasedBetDecisionRecorder::class)->record($prediction, $game))->toBeNull()
        ->and(BetDecision::count())->toBe(0);
});

it('records provisional selections with prices and policy identity without approving a wager', function () {
    [$game, $prediction, $snapshot, $odds] = decisionFixture();
    $this->mock(CfbCanonicalSpreadValueSignalService::class)->shouldReceive('forPrediction')->once()->andReturn([
        'decision_policy_version' => 'cfb-spread-decisions-2', 'decision_status' => 'provisional',
        'has_playable_value' => false, 'spread_assessment' => ['risk_flags' => ['spread_calibration_unavailable']],
        'market_confirmation' => ['side' => 'home', 'risk_flags' => [], 'best_quote' => [
            'quote_id' => 88, 'snapshot_id' => $odds->id, 'line' => 2.5, 'price' => -115, 'bookmaker' => 'fanduel',
        ]], 'best' => ['edge' => 2.5], 'cover_probability_evidence' => ['cover_probability' => null, 'risk_flags' => ['spread_calibration_unavailable']],
    ]);
    $decision = app(CfbReleasedBetDecisionRecorder::class)->record($prediction, $game);
    expect($decision->status)->toBe('provisional_candidate')->and($decision->recommendation_label)->toBe('model_lean')
        ->and($decision->is_bet)->toBeFalse()->and($decision->price)->toBe(-115)
        ->and($decision->model_probability)->toBeNull()->and($decision->projected_value)->toBeNull()
        ->and(data_get($decision->feature_snapshot, 'decision_policy_version'))->toBe('cfb-spread-decisions-2');
});
