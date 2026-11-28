<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\DailyDigestService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo('2026-03-22T15:00:00Z');
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    config(['alerts.daily_digest.sports' => ['nba'], 'ai.features.daily_digest_summary.enabled' => false,
        'prediction_lifecycle.canonical_reads.nba' => false, 'prediction_lifecycle.canonical_pipeline.nba' => false]);
    $props = Mockery::mock(PlayerPropAnalyzer::class);
    $props->shouldReceive('precomputedRecommendations')->andReturn(collect());
    app()->instance(PlayerPropAnalyzer::class, $props);
});

afterEach(fn () => $this->travelBack());

it('does not manufacture a certain away digest lean from a missing legacy probability', function () {
    $home = Team::factory()->create(['location' => 'Phoenix', 'name' => 'Suns', 'abbreviation' => 'PHX']);
    $away = Team::factory()->create(['location' => 'Denver', 'name' => 'Nuggets', 'abbreviation' => 'DEN']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => now()->startOfDay(), 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED', 'odds_data' => null]);
    $prediction = Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => -2.5,
        'predicted_total' => 225.0, 'win_probability' => null, 'confidence_score' => 72.0]);
    expect($prediction->fresh()->win_probability)->toBeNull();
    $payload = app(DailyDigestService::class)->buildDigestForUser(User::factory()->create(), now());
    if (($stateDirectory = getenv('DIGEST_BEFORE_STATE_DIR')) !== false && $stateDirectory !== '') {
        file_put_contents($stateDirectory.'/'.hash('sha256', $this->name()).'.json', json_encode([
            'stored' => Prediction::query()->sole()->fresh()->getRawOriginal(),
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    expect(collect($payload['predictions'] ?? [])->contains(fn (array $row): bool => ($row['pick_type'] ?? null) === 'model_lean' && ($row['confidence'] ?? null) === 100.0))->toBeFalse();
    expect($payload['predictions'] ?? [])->toBeEmpty()
        ->and(collect($payload['summary']['highlights'] ?? [])->contains(fn (string $highlight): bool => str_contains($highlight, '100.0%')))->toBeFalse();
    Mail::assertNothingOutgoing();
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

it('preserves the supported 58 percent home digest model lean', function () {
    $home = Team::factory()->create(['location' => 'Phoenix', 'name' => 'Suns', 'abbreviation' => 'PHX']);
    $away = Team::factory()->create(['location' => 'Denver', 'name' => 'Nuggets', 'abbreviation' => 'DEN']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => now()->startOfDay(), 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED', 'odds_data' => null]);
    Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => -2.5,
        'predicted_total' => 225.0, 'win_probability' => 0.58, 'confidence_score' => 72.0]);
    $payload = app(DailyDigestService::class)->buildDigestForUser(User::factory()->create(), now());
    if (($stateDirectory = getenv('DIGEST_BEFORE_STATE_DIR')) !== false && $stateDirectory !== '') {
        file_put_contents($stateDirectory.'/'.hash('sha256', $this->name()).'.json', json_encode([
            'stored' => Prediction::query()->sole()->fresh()->getRawOriginal(),
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    expect($payload['predictions'])->toHaveCount(1)
        ->and($payload['predictions'][0]['pick'])->toBe('Model lean: PHX moneyline')
        ->and($payload['predictions'][0]['confidence'])->toBe(58.0)
        ->and($payload['predictions'][0]['classification'])->toBe('Watchlist');
    Mail::assertNothingOutgoing();
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

it('preserves an observed zero home probability as an away model lean', function () {
    $home = Team::factory()->create(['location' => 'Phoenix', 'name' => 'Suns', 'abbreviation' => 'PHX']);
    $away = Team::factory()->create(['location' => 'Denver', 'name' => 'Nuggets', 'abbreviation' => 'DEN']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => now()->startOfDay(), 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED', 'odds_data' => null]);
    Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => -2.5,
        'predicted_total' => 225.0, 'win_probability' => 0.0, 'confidence_score' => 72.0]);
    $payload = app(DailyDigestService::class)->buildDigestForUser(User::factory()->create(), now());
    if (($stateDirectory = getenv('DIGEST_BEFORE_STATE_DIR')) !== false && $stateDirectory !== '') {
        file_put_contents($stateDirectory.'/'.hash('sha256', $this->name()).'.json', json_encode([
            'stored' => Prediction::query()->sole()->fresh()->getRawOriginal(),
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    expect($payload['predictions'])->toHaveCount(1)
        ->and($payload['predictions'][0]['pick'])->toBe('Model lean: DEN moneyline')
        ->and($payload['predictions'][0]['confidence'])->toBe(100.0)
        ->and($payload['predictions'][0]['classification'])->toBe('Watchlist');
    Mail::assertNothingOutgoing();
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

it('preserves a qualifying recommendation when fallback probability is missing', function () {
    $home = Team::factory()->create(['location' => 'Phoenix', 'name' => 'Suns', 'abbreviation' => 'PHX']);
    $away = Team::factory()->create(['location' => 'Denver', 'name' => 'Nuggets', 'abbreviation' => 'DEN']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => now()->startOfDay(), 'game_time' => '20:00:00', 'status' => 'STATUS_SCHEDULED', 'odds_data' => null]);
    Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => -2.5,
        'predicted_total' => 225.0, 'win_probability' => null, 'confidence_score' => 72.0]);
    $recommendations = Mockery::mock(GameBettingRecommendationService::class);
    $recommendations->shouldReceive('forPrediction')->once()->andReturn([[
        'type' => 'spread', 'recommendation' => 'Bet PHX -2.5', 'confidence' => 64.0, 'edge' => 4.0,
    ]]);
    app()->instance(GameBettingRecommendationService::class, $recommendations);
    $payload = app(DailyDigestService::class)->buildDigestForUser(User::factory()->create(), now());
    if (($stateDirectory = getenv('DIGEST_BEFORE_STATE_DIR')) !== false && $stateDirectory !== '') {
        file_put_contents($stateDirectory.'/'.hash('sha256', $this->name()).'.json', json_encode([
            'stored' => Prediction::query()->sole()->fresh()->getRawOriginal(),
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    expect($payload['predictions'])->toHaveCount(1)
        ->and($payload['predictions'][0]['pick'])->toBe('Bet PHX -2.5')
        ->and($payload['predictions'][0]['confidence'])->toBe(64.0)
        ->and($payload['predictions'][0]['classification'])->toBe('Bettable edge');
    Mail::assertNothingOutgoing();
    Http::assertNothingSent();
    Notification::assertNothingSent();
});
