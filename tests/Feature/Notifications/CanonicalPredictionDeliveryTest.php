<?php

use App\Mail\DailyPredictionsDigestMail;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\DailyDigestSend;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Prediction;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserAlertSent;
use App\Notifications\BettingValueAlert;
use App\Services\AlertService;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\DailyDigestService;
use App\Services\Predictions\PredictionDeliverySource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 28)->setTime(10, 0));
    config(['ai.features.daily_digest_summary.enabled' => false, 'alerts.daily_digest.sports' => ['nba'], 'alerts.daily_digest.enabled' => true, 'subscriptions.features.email_alerts_enabled' => true]);
    $props = Mockery::mock(PlayerPropAnalyzer::class);
    $props->shouldReceive('analyzeProps')->andReturn(collect());
    app()->instance(PlayerPropAnalyzer::class, $props);
    Notification::fake();
});

afterEach(fn () => $this->travelBack());

function predictionDeliveryFixture(string $sport = 'nba', ?float $probability = 0.72): array
{
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $event = SportEvent::factory()->create(['sport' => $sport, 'starts_at' => now()->addHours(4)]);
    $game = $gameClass::factory()->create([
        'sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
        'game_date' => now()->toDateString(), 'game_time' => now()->addHours(4)->toTimeString(),
        'status' => 'STATUS_SCHEDULED', 'odds_data' => ['bookmakers' => []],
    ]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $snapshot = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id]);
    $run = CalculationRun::factory()->create([
        'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded',
    ]);
    $prediction = CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
    ]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'moneyline', 'selection' => 'home', 'probability' => $probability, 'confidence_score' => 74]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -8.5]);
    PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 224.5]);
    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $prediction];
}

function predictionDeliveryDecision($game, CanonicalPrediction $prediction, array $overrides = []): BetDecision
{
    return BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(), 'decision_hash' => hash('sha256', Str::uuid()),
        'source_table' => 'predictions', 'source_id' => $prediction->id,
        'prediction_table' => 'predictions', 'prediction_id' => $prediction->id,
        'game_table' => $game->getTable(), 'game_id' => $game->id, 'sport' => $prediction->sport,
        'market_type' => 'moneyline', 'market_key' => 'h2h', 'side' => 'home', 'line' => null,
        'price' => 120, 'bookmaker' => 'stored-book', 'edge' => 0.08, 'confidence' => 0.74,
        'status' => 'released', 'recommendation_label' => 'model_bet',
        'is_public' => true, 'is_tracking_only' => false, 'is_bet' => true, 'pregame_safe' => true,
        'risk_flags' => [], 'eligibility_reasons' => [],
        'decided_at' => now(), 'game_start_at' => now()->addHours(4),
        ...$overrides,
    ]);
}

it('reads canonical delivery forecasts across sports without querying legacy predictions', function (string $sport) {
    [$game, $prediction] = predictionDeliveryFixture($sport);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $source = app(PredictionDeliverySource::class);
    $alerts = $source->forAlerts($sport);
    $digest = $source->forDigest($sport, now());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($alerts->sole()->source->id)->toBe($prediction->id)
        ->and($digest->sole()->game->id)->toBe($game->id)
        ->and($digest->sole()->predictedSpread)->toBe(8.5)
        ->and($digest->sole()->predictedTotal)->toBe(224.5)
        ->and($digest->sole()->homeWinProbability)->toBe(0.72)
        ->and(collect($queries)->contains(fn (array $query): bool => str_contains($query['query'], $sport.'_predictions')))->toBeFalse();
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba']);

it('uses exact canonical decisions and stored prices in betting recommendations and digests', function () {
    [$game, $prediction] = predictionDeliveryFixture();
    $decision = predictionDeliveryDecision($game, $prediction);
    $user = User::factory()->create();

    $recommendations = app(GameBettingRecommendationService::class)->forGame($game, 'nba');
    expect($recommendations)->toHaveCount(1)
        ->and($recommendations[0]['bet_decision_id'])->toBe($decision->id)
        ->and($recommendations[0]['odds'])->toBe(120.0)
        ->and($recommendations[0]['edge'])->toBe(8.0)
        ->and($recommendations[0]['confidence'])->toBe(74.0);

    $digest = app(DailyDigestService::class)->buildDigestForUser($user, now());
    expect($digest['predictions'])->toHaveCount(1)
        ->and($digest['predictions'][0]['pick_type'])->toBe('moneyline')
        ->and($digest['predictions'][0]['predicted_spread'])->toBe(8.5)
        ->and($digest['predictions'][0]['confidence'])->toBe(74.0);
});

it('keeps held private unsafe and tracking decisions out of public recommendations', function (array $overrides) {
    [$game, $prediction] = predictionDeliveryFixture();
    if (($overrides['decided_at'] ?? null) === 'future') {
        $overrides['decided_at'] = now()->addMinute();
    }
    predictionDeliveryDecision($game, $prediction, $overrides);

    expect(app(GameBettingRecommendationService::class)->forGame($game, 'nba'))->toBe([])
        ->and(app(AlertService::class)->checkForValueOpportunities('nba'))->toBe(0);
    $digest = app(DailyDigestService::class)->buildDigestForUser(User::factory()->create(), now());
    expect($digest['predictions'][0]['pick_type'])->toBe('model_lean')
        ->and($digest['predictions'][0]['classification'])->toBe('Watchlist');
    Notification::assertNothingSent();
})->with([
    'held' => [['is_bet' => false, 'status' => 'held_candidate']],
    'tracking' => [['is_tracking_only' => true]],
    'private' => [['is_public' => false]],
    'unsafe' => [['pregame_safe' => false]],
    'legacy identity' => [['prediction_table' => 'nba_predictions']],
    'other prediction revision' => [['prediction_id' => 99999]],
    'unpriced' => [['price' => null]],
    'future decision' => [['decided_at' => 'future']],
    'held status' => [['status' => 'held_candidate']],
    'no-bet label' => [['recommendation_label' => 'no_bet']],
]);

it('does not resurrect an older approved decision after a later hold', function () {
    [$game, $prediction] = predictionDeliveryFixture();
    predictionDeliveryDecision($game, $prediction);
    predictionDeliveryDecision($game, $prediction, ['is_bet' => false, 'is_public' => false, 'status' => 'held_candidate']);

    expect(app(GameBettingRecommendationService::class)->forGame($game, 'nba'))->toBe([]);
});

it('does not fall back to a legacy forecast when canonical delivery coverage disappears', function () {
    [$game, $prediction] = predictionDeliveryFixture();
    Prediction::factory()->create(['game_id' => $game->id, 'win_probability' => 0.99]);
    predictionDeliveryDecision($game, $prediction);
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

    expect(app(PredictionDeliverySource::class)->forAlerts('nba'))->toBeEmpty()
        ->and(app(PredictionDeliverySource::class)->forDigest('nba', now()))->toBeEmpty()
        ->and(app(GameBettingRecommendationService::class)->forGame($game, 'nba'))->toBe([])
        ->and(app(DailyDigestService::class)->buildDigestForUser(User::factory()->create(), now()))->toBeNull();
});

it('delivers canonical alerts through existing tier limits and preserves canonical history identity', function () {
    $this->artisan('tiers:sync')->assertSuccessful();
    $tier = SubscriptionTier::query()->where('slug', 'basic')->firstOrFail();
    $price = $tier->stripe_price_id_monthly ?: 'test_basic_delivery';
    $tier->update(['stripe_price_id_monthly' => $price]);
    $user = User::factory()->create();
    $user->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_delivery', 'stripe_status' => 'active', 'stripe_price' => $price, 'quantity' => 1,
    ]);
    UserAlertPreference::factory()->create([
        'user_id' => $user->id, 'enabled' => true, 'sports' => ['nba'],
        'notification_types' => ['email'], 'minimum_edge' => 5,
        'time_window_start' => '00:00', 'time_window_end' => '23:59', 'digest_mode' => 'realtime',
    ]);
    [$game, $prediction] = predictionDeliveryFixture();
    predictionDeliveryDecision($game, $prediction);

    expect(app(AlertService::class)->checkForValueOpportunities('nba'))->toBe(1);
    Notification::assertSentTo($user, BettingValueAlert::class, function (BettingValueAlert $notification) use ($prediction, $user, $game): bool {
        $copy = unserialize(serialize($notification));
        expect($copy->toArray($user)['game_id'])->toBe($game->id)
            ->and($copy->toMail($user)->subject)->toContain('@')
            ->and($copy->toWhatsApp($user))->toContain('Value Alert')
            ->and($copy->toWebPush($user)['data']['game_id'])->toBe($game->id);

        return $copy->prediction instanceof CanonicalPrediction && $copy->prediction->id === $prediction->id;
    });
    $sent = UserAlertSent::query()->sole();
    expect($sent->prediction_id)->toBe($prediction->id)
        ->and($sent->prediction_type)->toBe(CanonicalPrediction::class);
});

it('rechecks approval when a canonical alert leaves the queue', function () {
    [$game, $prediction] = predictionDeliveryFixture();
    $decision = predictionDeliveryDecision($game, $prediction);
    $user = User::factory()->create();
    $queued = unserialize(serialize(new BettingValueAlert($prediction, 'nba', 8, 'Bet home ML', null, $decision->id)));

    expect($queued->shouldSend($user, 'mail'))->toBeTrue();
    predictionDeliveryDecision($game, $prediction, ['is_bet' => false, 'is_public' => false, 'status' => 'held_candidate']);
    expect($queued->shouldSend($user, 'mail'))->toBeFalse();
});

it('does not deliver queued canonical alerts after their forecast is withdrawn', function () {
    [$game, $prediction] = predictionDeliveryFixture();
    $decision = predictionDeliveryDecision($game, $prediction);
    $queued = unserialize(serialize(new BettingValueAlert($prediction, 'nba', 8, 'Bet home ML', null, $decision->id)));
    $prediction->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

    expect($queued->shouldSend(User::factory()->create(), 'mail'))->toBeFalse();
});

it('does not invent an away probability when the canonical home probability is missing', function () {
    predictionDeliveryFixture('nba', null);

    expect(app(DailyDigestService::class)->buildDigestForUser(User::factory()->create(), now()))->toBeNull();
});

it('does not attach a canonical betting decision to a game from another sport', function () {
    [$game] = predictionDeliveryFixture();
    predictionDeliveryFixture('nfl');

    expect(app(GameBettingRecommendationService::class)->forGame($game, 'nfl'))->toBe([]);
});

it('uses the frozen canonical entry price without requiring current game odds', function () {
    [$game, $prediction] = predictionDeliveryFixture();
    $game->update(['odds_data' => null]);
    predictionDeliveryDecision($game, $prediction);

    expect(app(PredictionDeliverySource::class)->forAlerts('nba'))->toHaveCount(1);
    $recommendations = app(GameBettingRecommendationService::class)->forGame($game, 'nba');
    expect($recommendations)->toHaveCount(1)
        ->and($recommendations[0]['odds'])->toBe(120.0);
});

it('does not treat a recent decision as a fresh observation of an old quote', function () {
    [$game, $prediction] = predictionDeliveryFixture();
    predictionDeliveryDecision($game, $prediction, [
        'market_snapshot' => ['quote' => ['captured_at' => now()->subHours(37)->toIso8601String()]],
    ]);

    expect(app(GameBettingRecommendationService::class)->forGame($game, 'nba'))->toBe([]);
});

it('sends the due canonical digest with existing history and model-lean behavior', function () {
    Mail::fake();
    predictionDeliveryFixture();
    $user = User::factory()->create();
    UserAlertPreference::factory()->create([
        'user_id' => $user->id, 'daily_digest_subscribed' => true, 'digest_time' => '09:00',
    ]);

    expect(app(DailyDigestService::class)->sendDueDigests(now()))->toBe(1)
        ->and(DailyDigestSend::query()->where('user_id', $user->id)->sole()->predictions_count)->toBe(1);
    Mail::assertSent(DailyPredictionsDigestMail::class, fn ($mail): bool => $mail->hasTo($user->email)
        && $mail->predictions[0]['classification'] === 'Watchlist'
        && $mail->predictions[0]['predicted_spread'] === 8.5);
});

it('preserves sport priority when allocating the existing daily alert limits', function () {
    expect(array_keys(app(AlertService::class)->checkAllSports()))
        ->toBe(['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba']);
});

it('does not deliver a previously queued legacy alert after the sport switches to canonical reads', function () {
    [$game] = predictionDeliveryFixture();
    $legacy = Prediction::factory()->create(['game_id' => $game->id]);
    $queued = new BettingValueAlert($legacy, 'nba', 8, 'Bet home ML');

    expect($queued->shouldSend(User::factory()->create(), 'mail'))->toBeFalse();
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    expect($queued->shouldSend(User::factory()->create(), 'mail'))->toBeTrue();
});

it('preserves spread and total line conventions and edge units for stored canonical decisions', function (array $market, float $edge, float $modelLine, float $marketLine) {
    [$game, $prediction] = predictionDeliveryFixture();
    predictionDeliveryDecision($game, $prediction, $market);
    $recommendations = app(GameBettingRecommendationService::class)->forGame($game, 'nba');

    expect($recommendations)->toHaveCount(1)
        ->and($recommendations[0]['edge'])->toBe($edge)
        ->and($recommendations[0]['model_line'])->toBe($modelLine)
        ->and($recommendations[0]['market_line'])->toBe($marketLine)
        ->and($recommendations[0]['line'])->toBe($market['line']);
})->with([
    'home spread' => [['market_type' => 'spread', 'market_key' => 'spreads', 'side' => 'home', 'line' => -3.5], 5.0, -8.5, -3.5],
    'away spread' => [['market_type' => 'spread', 'market_key' => 'spreads', 'side' => 'away', 'line' => 10.5], 2.0, -8.5, -10.5],
    'over' => [['market_type' => 'total', 'market_key' => 'totals', 'side' => 'over', 'line' => 220.5], 4.0, 224.5, 220.5],
    'under' => [['market_type' => 'total', 'market_key' => 'totals', 'side' => 'under', 'line' => 228.5], 4.0, 224.5, 228.5],
]);
