<?php

use App\AI\Agents\DailyDigestSummaryAgent;
use App\Mail\DailyPredictionsDigestMail;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\DailyDigestService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-22 10:00:00'));

    $mock = Mockery::mock(PlayerPropAnalyzer::class);
    $mock->shouldReceive('analyzeProps')->andReturn(new Collection);
    app()->instance(PlayerPropAnalyzer::class, $mock);
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeDailyDigestUser(): User
{
    $user = User::factory()->create();

    UserAlertPreference::query()->create([
        'user_id' => $user->id,
        'enabled' => true,
        'sports' => ['nba'],
        'notification_types' => ['email'],
        'enabled_template_ids' => [],
        'minimum_edge' => 3.0,
        'time_window_start' => Carbon::parse('09:00:00'),
        'time_window_end' => Carbon::parse('23:00:00'),
        'digest_mode' => 'daily_summary',
        'digest_time' => Carbon::parse('10:00:00'),
    ]);

    return $user->fresh('alertPreference');
}

function makeDailyDigestPrediction(): Prediction
{
    $home = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Lakers',
        'abbreviation' => 'LAL',
    ]);
    $away = Team::factory()->create([
        'location' => 'Boston',
        'name' => 'Celtics',
        'abbreviation' => 'BOS',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => now()->copy()->addHours(4),
        'status' => 'STATUS_SCHEDULED',
    ]);

    return Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.63,
        'confidence_score' => 74.2,
    ]);
}

test('build digest for user includes ai-generated summary when enabled', function () {
    config()->set('ai.features.daily_digest_summary.enabled', true);
    config()->set('ai.features.daily_digest_summary.provider', 'openai');
    config()->set('ai.features.daily_digest_summary.model', 'gpt-4o-mini');
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('services.openai.api_key', 'test-openai-key');

    DailyDigestSummaryAgent::fake([
        [
            'headline' => 'NBA board at a glance',
            'intro' => 'The model sees one clear NBA moneyline lean on today\'s board.',
            'highlights' => [
                'BOS @ LAL leans BOS moneyline at 63.0% confidence.',
            ],
        ],
    ])->preventStrayPrompts();

    $user = makeDailyDigestUser();
    makeDailyDigestPrediction();

    $payload = app(DailyDigestService::class)->buildDigestForUser($user, now());

    expect($payload)->not->toBeNull()
        ->and($payload['summary']['headline'])->toBe('NBA board at a glance')
        ->and($payload['summary']['intro'])->toContain('clear NBA moneyline lean')
        ->and($payload['summary']['highlights'])->toHaveCount(1)
        ->and($payload['predictions'])->toHaveCount(1);
});

test('build digest for user falls back to deterministic summary when ai digest summary is disabled', function () {
    config()->set('ai.features.daily_digest_summary.enabled', false);

    $user = makeDailyDigestUser();
    makeDailyDigestPrediction();

    $payload = app(DailyDigestService::class)->buildDigestForUser($user, now());

    expect($payload)->not->toBeNull()
        ->and($payload['summary']['headline'])->toBe('NBA Daily Digest')
        ->and($payload['summary']['intro'])->toContain('model-driven spots across NBA')
        ->and($payload['summary']['highlights'])->not->toBeEmpty();
});

test('daily digest mail renders summary headline and highlights', function () {
    $user = User::factory()->create(['name' => 'Taylor']);

    $mail = new DailyPredictionsDigestMail(
        user: $user,
        summary: [
            'headline' => 'NBA board at a glance',
            'intro' => 'The model sees one clear lean tonight.',
            'highlights' => [
                'BOS @ LAL leans BOS moneyline at 63.0% confidence.',
            ],
        ],
        predictions: [],
        playerProps: [],
    );

    $rendered = $mail->render();

    expect($rendered)->toContain('NBA board at a glance')
        ->and($rendered)->toContain('The model sees one clear lean tonight.')
        ->and($rendered)->toContain('BOS @ LAL leans BOS moneyline at 63.0% confidence.');
});
