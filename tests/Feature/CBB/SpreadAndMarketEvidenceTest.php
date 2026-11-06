<?php

use App\Actions\CBB\GenerateCanonicalPrediction;
use App\Actions\CBB\GeneratePrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\GameOddsSnapshot;
use App\Models\SportEvent;
use App\Models\SportEventResult;
use App\Models\User;
use App\Services\CBB\CbbScoreMarginRatings;
use App\Services\CBB\Predictions\CbbCalculationReleaseRegistrar;
use App\Services\CBB\Predictions\CbbPregameMarketSnapshot;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\CanonicalPredictionEvaluator;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->travelTo('2026-11-05 12:00:00');
    $this->home = Team::factory()->create(['school' => 'Arkansas', 'mascot' => 'Razorbacks', 'elo_rating' => 1500]);
    $this->away = Team::factory()->create(['school' => 'Arkansas-Pine Bluff', 'mascot' => 'Golden Lions', 'elo_rating' => 1500]);
    $this->event = SportEvent::factory()->create(['sport' => 'cbb', 'season' => 2027, 'season_type' => '2', 'starts_at' => now()->addDay(), 'neutral_site' => true]);
    $tip = $this->event->starts_at->utc();
    $this->game = Game::factory()->create(['sport_event_id' => $this->event->id, 'home_team_id' => $this->home->id,
        'away_team_id' => $this->away->id, 'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $tip->toDateString(), 'game_time' => $tip->toTimeString(), 'neutral_site' => true, 'odds_data' => null, 'odds_updated_at' => null]);
});

function cbbMarketPayload(bool $total = true): array
{
    return ['home_team' => 'Arkansas Razorbacks', 'away_team' => 'Arkansas-Pine Bluff Golden Lions', 'bookmakers' => [
        ['key' => 'testbook', 'title' => 'Test Book', 'markets' => array_values(array_filter([
            ['key' => 'spreads', 'outcomes' => [
                ['name' => 'Arkansas-Pine Bluff Golden Lions', 'point' => 5.5, 'price' => -105],
                ['name' => 'Arkansas Razorbacks', 'point' => -5.5, 'price' => -115],
            ]],
            $total ? ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 200, 'price' => -110], ['name' => 'Under', 'point' => 200, 'price' => -110]]] : null,
        ]))],
    ]];
}

function cbbRecordMarket(Game $game, SportEvent $event, bool $total = true): GameOddsSnapshot
{
    return app(GameOddsSnapshotRecorder::class)->record('cbb', $game,
        ['id' => 'odds-cbb-test', 'commence_time' => $event->starts_at->toIso8601String()], cbbMarketPayload($total));
}

function cbbMarginFinal(Team $home, Team $away, int $margin, string $date = '2026-03-01', int $season = 2026): Game
{
    return Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => $season, 'season_type' => 2, 'game_date' => $date, 'neutral_site' => true,
        'status' => 'STATUS_FINAL', 'home_score' => 100 + $margin, 'away_score' => 100]);
}

it('never matches a home school substring against the visiting school spread', function (): void {
    $this->game->update(['odds_data' => cbbMarketPayload(), 'odds_updated_at' => now()]);
    $first = app(GeneratePrediction::class)->preview($this->game->fresh());
    $data = cbbMarketPayload();
    $data['bookmakers'][0]['markets'][0]['outcomes'] = array_reverse($data['bookmakers'][0]['markets'][0]['outcomes']);
    $this->game->update(['odds_data' => $data]);
    $second = app(GeneratePrediction::class)->preview($this->game->fresh());
    expect($first['_snapshot']['features']['vegas_spread'])->toBe(-5.5)
        ->and($first['predicted_spread'])->toBe($second['predicted_spread']);
});

it('uses opponent-adjusted score history without requiring box scores or overwriting prior releases', function (): void {
    $middle = Team::factory()->create();
    cbbMarginFinal($this->home, $middle, 20);
    cbbMarginFinal($middle, $this->away, 30);
    cbbMarginFinal($this->home, $this->away, 50);
    $old = app(GeneratePrediction::class)->preview($this->game->fresh());
    config(['cbb.prediction.score_margin.enabled' => true]);
    $new = app(GeneratePrediction::class)->preview($this->game->fresh());
    expect($new['predicted_spread'])->toBe(35.0)
        ->and($new['model_version'])->toBe('cbb-score-margin-v1')
        ->and(data_get($new, 'model_metadata.score_margin_rating.source_games'))->toBe(3)
        ->and($old['model_version'])->toBe('cbb-season-v2')
        ->and($old['predicted_spread'])->toBe(0.0);
});

it('excludes target-day and future scores and reports missing team history explicitly', function (): void {
    cbbMarginFinal($this->home, $this->away, 80, '2026-11-06', 2027);
    cbbMarginFinal($this->home, $this->away, 90, '2026-11-07', 2027);
    $rating = app(CbbScoreMarginRatings::class)->forGame($this->game);
    expect($rating['available'])->toBeFalse()->and($rating['source_games'])->toBe(0)
        ->and($rating['reason'])->toBe('missing_team_score_history');
});

it('captures quote ids prices and independent spread coverage before tipoff', function (): void {
    $snapshot = cbbRecordMarket($this->game, $this->event, false);
    $market = app(CbbPregameMarketSnapshot::class)->capture($this->game, now()->toImmutable(), $this->event->starts_at->toImmutable());
    expect($market['available'])->toBeTrue()->and($market['game_odds_snapshot_id'])->toBe($snapshot->id)
        ->and($market['market_coverage'])->toMatchArray(['spread' => true, 'total' => false])
        ->and($market['consensus']['spread']['line'])->toBe(-5.5)
        ->and($market['consensus']['spread']['price'])->toBe(-115)
        ->and($market['consensus']['spread']['opposite_quote']['line'])->toBe(5.5)
        ->and($market['consensus']['total'])->toBeNull();
});

it('freezes market evidence in the new release and grades ATS and totals without mutable odds', function (): void {
    cbbMarginFinal($this->home, $this->away, 40);
    $snapshot = cbbRecordMarket($this->game, $this->event);
    app(CbbCalculationReleaseRegistrar::class)->register('1.2.1', effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($this->game->fresh());
    $input = $prediction->calculationRun->inputSnapshot;
    expect(data_get($input->inputs, 'pregame_market.game_odds_snapshot_id'))->toBe($snapshot->id)
        ->and(data_get($input->inputs, 'established_model.model_version'))->toBe('cbb-score-margin-v1')
        ->and(data_get($input->inputs, 'established_model.evidence.features.vegas_spread'))->toBe(-5.5);
    $this->travelTo($this->event->starts_at->addHour());
    $this->game->update(['odds_data' => [], 'odds_updated_at' => now()]);
    $result = SportEventResult::factory()->create(['sport_event_id' => $this->event->id, 'home_score' => 90, 'away_score' => 70]);
    $evaluation = app(CanonicalPredictionEvaluator::class)->evaluate($prediction, $result);
    expect($evaluation->scoring_version)->toBe('canonical-cbb-market-v1')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.spread.selection'))->toBe('home')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.spread.selection_price'))->toBe(-115)
        ->and(data_get($evaluation->market_comparison, 'pregame_market.spread.result'))->toBe('win')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.total.selection'))->toBe('under')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.total.result'))->toBe('win')
        ->and($input->fresh()->inputs)->toBe($input->inputs);
    expect(app(CanonicalPredictionEvaluator::class)->evaluate($prediction, $result)->id)->toBe($evaluation->id);
    config(['prediction_lifecycle.canonical_reads.cbb' => true, 'subscriptions.enforce_tiers' => false]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v2/sports/cbb/predictions/'.$prediction->public_id)->assertSuccessful()
        ->assertJsonPath('data.ats_pick_result', 'win')
        ->assertJsonPath('data.total_pick_result', 'win')
        ->assertJsonPath('data.pregame_market.consensus.spread.line', -5.5)
        ->assertJsonPath('data.market_evaluation.status', 'tracking_only');

});

it('recovers only archived quotes actually known before an older forecast without rewriting its input', function (): void {
    $snapshot = cbbRecordMarket($this->game, $this->event);
    app(CbbCalculationReleaseRegistrar::class)->register('1.1.0', effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($this->game->fresh());
    $input = $prediction->calculationRun->inputSnapshot;
    $legacyInputs = $input->inputs;
    unset($legacyInputs['pregame_market']);
    DB::table('event_input_snapshots')->where('id', $input->id)->update(['inputs' => json_encode($legacyInputs)]);
    $prediction->unsetRelation('calculationRun');
    $this->travelTo($this->event->starts_at->addHour());
    $result = SportEventResult::factory()->create(['sport_event_id' => $this->event->id, 'home_score' => 90, 'away_score' => 70]);
    $evaluation = app(CanonicalPredictionEvaluator::class)->evaluate($prediction, $result);
    expect(data_get($evaluation->market_comparison, 'pregame_market.evidence_origin'))->toBe('archived_quotes_known_at_forecast')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.game_odds_snapshot_id'))->toBe($snapshot->id)
        ->and(data_get($evaluation->market_comparison, 'pregame_market.spread.market_home_line'))->toBe(-5.5)
        ->and($input->fresh()->inputs)->toBe($legacyInputs);
});

it('rejects late-ingested backdated quotes and quotes for another kickoff', function (): void {
    $asOf = now()->toImmutable();
    $this->travel(10)->minutes();
    $snapshot = cbbRecordMarket($this->game, $this->event);
    DB::table('game_odds_snapshots')->where('id', $snapshot->id)->update(['captured_at' => $asOf]);
    DB::table('market_quotes')->where('game_odds_snapshot_id', $snapshot->id)->update(['captured_at' => $asOf]);
    $market = app(CbbPregameMarketSnapshot::class)->capture($this->game, $asOf, $this->event->starts_at->toImmutable());
    expect($market['available'])->toBeFalse();
    $market = app(CbbPregameMarketSnapshot::class)->capture($this->game, now()->toImmutable(), $this->event->starts_at->addDay()->toImmutable());
    expect($market['available'])->toBeFalse();
});

it('keeps large score-history favorites evaluable without perfect-certainty probabilities', function (): void {
    cbbMarginFinal($this->home, $this->away, 100);
    config(['cbb.prediction.score_margin.enabled' => true]);
    $preview = app(GeneratePrediction::class)->preview($this->game->fresh());
    expect($preview['predicted_spread'])->toBe(70.0)
        ->and($preview['win_probability'])->toBe(0.999);
});

it('grades pushes and leaves an absent total ungraded', function (): void {
    cbbMarginFinal($this->home, $this->away, 40);
    $payload = cbbMarketPayload(false);
    $payload['bookmakers'][0]['markets'][0]['outcomes'][0]['point'] = 6;
    $payload['bookmakers'][0]['markets'][0]['outcomes'][1]['point'] = -6;
    app(GameOddsSnapshotRecorder::class)->record('cbb', $this->game,
        ['id' => 'push-odds', 'commence_time' => $this->event->starts_at->toIso8601String()], $payload);
    app(CbbCalculationReleaseRegistrar::class)->register('1.2.1', effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($this->game->fresh());
    $this->travelTo($this->event->starts_at->addHour());
    $result = SportEventResult::factory()->create(['sport_event_id' => $this->event->id, 'home_score' => 86, 'away_score' => 80]);
    $evaluation = app(CanonicalPredictionEvaluator::class)->evaluate($prediction, $result);
    expect(data_get($evaluation->market_comparison, 'pregame_market.spread.result'))->toBe('push')
        ->and(data_get($evaluation->market_comparison, 'pregame_market.total.result'))->toBeNull();
});

it('updates prior score strength gradually from completed current-season games only', function (): void {
    cbbMarginFinal($this->home, $this->away, 100);
    cbbMarginFinal($this->home, $this->away, 20, '2026-11-04', 2027);
    cbbMarginFinal($this->home, $this->away, 100, '2026-11-07', 2027);
    $rating = app(CbbScoreMarginRatings::class)->forGame($this->game);
    expect(round($rating['home_margin'], 1))->toBe(60.0)
        ->and($rating['source_games'])->toBe(2)
        ->and($rating['home']['current_games'])->toBe(1)
        ->and($rating['away']['prior_games'])->toBe(1);
});

it('persists a fresh confirmation of an unchanged CBB market after thirty minutes', function (): void {
    $first = cbbRecordMarket($this->game, $this->event);
    $this->travel(31)->minutes();
    $second = cbbRecordMarket($this->game, $this->event);
    expect($second->id)->not->toBe($first->id);
    $market = app(CbbPregameMarketSnapshot::class)->capture($this->game, now()->toImmutable(), $this->event->starts_at->toImmutable());
    expect($market['game_odds_snapshot_id'])->toBe($second->id)->and($market['available'])->toBeTrue();
});

it('normalizes score-rating evidence before JSON hashing and SQL persistence', function (): void {
    $middle = Team::factory()->create();
    cbbMarginFinal($this->home, $middle, 7);
    cbbMarginFinal($middle, $this->away, 11);
    cbbMarginFinal($this->home, $this->away, 19);
    $ratings = app(CbbScoreMarginRatings::class)->forGame($this->game);
    foreach ([$ratings['home']['rating'], $ratings['away']['rating'], $ratings['home_margin']] as $value) {
        expect($value)->toBe(round($value, 6));
    }
    app(CbbCalculationReleaseRegistrar::class)->register('1.2.1', effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($this->game->fresh());
    expect($prediction->publication_state)->toBe('published')
        ->and(data_get($prediction->output_metadata, 'model_metadata.score_margin_rating.home_margin'))->toBe($ratings['home_margin']);
});
