<?php

use App\AI\Agents\DailyDigestSummaryAgent;
use App\Mail\DailyPredictionsDigestMail;
use App\Models\DailyDigestSend;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\DailyDigestService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-03-22 10:00:00'));

    $mock = Mockery::mock(PlayerPropAnalyzer::class);
    $mock->shouldReceive('analyzeProps')->andReturn(new Collection);
    app()->instance(PlayerPropAnalyzer::class, $mock);
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeDailyDigestOddsData(
    string $homeTeam = 'Los Angeles Lakers',
    string $awayTeam = 'Boston Celtics',
    float $homeSpread = -2.5,
    float $total = 226.5,
    int $homeMoneyline = -130,
    int $awayMoneyline = 110,
): array {
    return [
        'home_team' => $homeTeam,
        'away_team' => $awayTeam,
        'bookmakers' => [[
            'markets' => [
                [
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => $homeTeam, 'point' => $homeSpread, 'price' => -110],
                        ['name' => $awayTeam, 'point' => abs($homeSpread), 'price' => -110],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => $total, 'price' => -110],
                        ['name' => 'Under', 'point' => $total, 'price' => -110],
                    ],
                ],
                [
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => $homeTeam, 'price' => $homeMoneyline],
                        ['name' => $awayTeam, 'price' => $awayMoneyline],
                    ],
                ],
            ],
        ]],
    ];
}

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
        'daily_digest_subscribed' => true,
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
        'odds_data' => makeDailyDigestOddsData(
            homeTeam: 'Los Angeles Lakers',
            awayTeam: 'Boston Celtics',
            homeSpread: -2.5,
            total: 226.5,
            homeMoneyline: -130,
            awayMoneyline: 110,
        ),
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

test('daily digest skips before building payloads when production mail transport is localhost', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config()->set('alerts.daily_digest.enabled', true);
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', '127.0.0.1');
    config()->set('mail.mailers.smtp.port', 2525);
    config()->set('ai.features.daily_digest_summary.enabled', true);

    Mail::fake();
    DailyDigestSummaryAgent::fake([])->preventStrayPrompts();

    makeDailyDigestUser();
    makeDailyDigestPrediction();

    $sent = app(DailyDigestService::class)->sendDueDigests(now());

    expect($sent)->toBe(0)
        ->and(DailyDigestSend::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

test('daily digest can be disabled by config', function () {
    config()->set('alerts.daily_digest.enabled', false);

    Mail::fake();
    DailyDigestSummaryAgent::fake([])->preventStrayPrompts();

    makeDailyDigestUser();
    makeDailyDigestPrediction();

    $sent = app(DailyDigestService::class)->sendDueDigests(now());

    expect($sent)->toBe(0)
        ->and(DailyDigestSend::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

test('build digest for user falls back to deterministic summary when ai digest summary is disabled', function () {
    config()->set('ai.features.daily_digest_summary.enabled', false);

    $user = makeDailyDigestUser();
    makeDailyDigestPrediction();

    $payload = app(DailyDigestService::class)->buildDigestForUser($user, now());

    expect($payload)->not->toBeNull()
        ->and($payload['summary']['headline'])->toBe('Today\'s NBA Picks')
        ->and($payload['summary']['intro'])->toContain('1 official bet and 0 watchlist leans today.')
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
        ->and($rendered)->toContain('The model sees one clear lean tonight.');
    expect($rendered)->toContain('Today’s board');
});

test('daily digest is due for users without alert preferences by default', function () {
    $user = User::factory()->create();

    expect(app(DailyDigestService::class)->isDueForDigest($user, now()))->toBeTrue();
});

test('daily digest remains due after the target time until it has been sent', function () {
    $user = User::factory()->create();

    expect(app(DailyDigestService::class)->isDueForDigest($user, now()->copy()->setTime(9, 59)))->toBeFalse();
    expect(app(DailyDigestService::class)->isDueForDigest($user, now()->copy()->setTime(10, 0)))->toBeTrue();
    expect(app(DailyDigestService::class)->isDueForDigest($user, now()->copy()->setTime(12, 30)))->toBeTrue();
});

test('daily digest is not due when user has unsubscribed', function () {
    $user = makeDailyDigestUser();
    $user->alertPreference->update([
        'daily_digest_subscribed' => false,
    ]);

    expect(app(DailyDigestService::class)->isDueForDigest($user->fresh('alertPreference'), now()))->toBeFalse();
});

test('daily digest uses game time and excludes next day games', function () {
    $user = makeDailyDigestUser();

    $homeToday = Team::factory()->create([
        'location' => 'Houston',
        'name' => 'Rockets',
        'abbreviation' => 'HOU',
    ]);
    $awayToday = Team::factory()->create([
        'location' => 'Memphis',
        'name' => 'Grizzlies',
        'abbreviation' => 'MEM',
    ]);

    $todayGame = Game::factory()->create([
        'home_team_id' => $homeToday->id,
        'away_team_id' => $awayToday->id,
        'game_date' => now()->copy()->startOfDay(),
        'game_time' => '19:30:00',
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => makeDailyDigestOddsData(
            homeTeam: 'Houston Rockets',
            awayTeam: 'Memphis Grizzlies',
            homeSpread: -2.5,
            total: 219.5,
            homeMoneyline: -135,
            awayMoneyline: 115,
        ),
    ]);

    Prediction::query()->create([
        'game_id' => $todayGame->id,
        'predicted_spread' => 3.5,
        'predicted_total' => 221.5,
        'win_probability' => 0.63,
        'confidence_score' => 74.2,
    ]);

    $homeTomorrow = Team::factory()->create([
        'location' => 'Boston',
        'name' => 'Celtics',
        'abbreviation' => 'BOS',
    ]);
    $awayTomorrow = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Bulls',
        'abbreviation' => 'CHI',
    ]);

    $tomorrowGame = Game::factory()->create([
        'home_team_id' => $homeTomorrow->id,
        'away_team_id' => $awayTomorrow->id,
        'game_date' => now()->copy()->addDay()->startOfDay(),
        'game_time' => '18:00:00',
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => makeDailyDigestOddsData(
            homeTeam: 'Boston Celtics',
            awayTeam: 'Chicago Bulls',
            homeSpread: -4.5,
            total: 228.5,
            homeMoneyline: -150,
            awayMoneyline: 130,
        ),
    ]);

    Prediction::query()->create([
        'game_id' => $tomorrowGame->id,
        'predicted_spread' => -5.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.70,
        'confidence_score' => 82.0,
    ]);

    $payload = app(DailyDigestService::class)->buildDigestForUser($user, now());

    expect($payload)->not->toBeNull();
    expect($payload['predictions'])->toHaveCount(1);
    expect($payload['predictions'][0]['matchup'])->toBe('MEM @ HOU');
    expect($payload['predictions'][0]['game_time'])->toBe(now()->copy()->setTime(19, 30)->format('M j, g:i A'));
});

test('daily digest uses recommendation confidence instead of raw lock-level probability', function () {
    $user = makeDailyDigestUser();

    $home = Team::factory()->create([
        'location' => 'Miami',
        'name' => 'Marlins',
        'abbreviation' => 'MIA',
    ]);
    $away = Team::factory()->create([
        'location' => 'Colorado',
        'name' => 'Rockies',
        'abbreviation' => 'COL',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => now()->copy()->startOfDay(),
        'game_time' => '19:10:00',
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => makeDailyDigestOddsData(
            homeTeam: 'Miami Marlins',
            awayTeam: 'Colorado Rockies',
            homeSpread: -1.5,
            total: 8.5,
            homeMoneyline: -2000,
            awayMoneyline: 900,
        ),
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => -1.5,
        'predicted_total' => 8.5,
        'win_probability' => 1.0,
        'confidence_score' => 100.0,
    ]);

    $payload = app(DailyDigestService::class)->buildDigestForUser($user, now());

    expect($payload)->not->toBeNull();
    expect($payload['predictions'])->toHaveCount(1);
    expect($payload['predictions'][0]['confidence'])->toBe(69.0);
    expect($payload['predictions'][0]['confidence'])->toBeLessThan(95.0);
});

test('daily digest falls back to model leans without a qualifying recommendation', function () {
    $user = makeDailyDigestUser();

    $home = Team::factory()->create([
        'location' => 'Phoenix',
        'name' => 'Suns',
        'abbreviation' => 'PHX',
    ]);
    $away = Team::factory()->create([
        'location' => 'Denver',
        'name' => 'Nuggets',
        'abbreviation' => 'DEN',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'game_date' => now()->copy()->startOfDay(),
        'game_time' => '20:00:00',
        'status' => 'STATUS_SCHEDULED',
    ]);

    Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => -2.5,
        'predicted_total' => 225.0,
        'win_probability' => 0.58,
        'confidence_score' => 72.0,
    ]);

    $payload = app(DailyDigestService::class)->buildDigestForUser($user, now());

    expect($payload)->not->toBeNull();
    expect($payload['predictions'])->toHaveCount(1);
    expect($payload['predictions'][0]['matchup'])->toBe('DEN @ PHX');
    expect($payload['predictions'][0]['pick'])->toBe('Model lean: PHX moneyline');
    expect($payload['predictions'][0]['bet_label'])->toBe('PHX ML');
    expect($payload['predictions'][0]['classification'])->toBe('Watchlist');
    expect($payload['predictions'][0]['market_note'])->toBe('Waiting on market odds before bet classification.');
    expect($payload['predictions'][0]['pick_type'])->toBe('model_lean');
    expect($payload['predictions'][0]['edge'])->toBe(0.0);
});

test('daily digest ranks predictions by strongest recommendation edge', function () {
    $user = makeDailyDigestUser();

    $homeA = Team::factory()->create(['location' => 'New York', 'name' => 'Knicks', 'abbreviation' => 'NYK']);
    $awayA = Team::factory()->create(['location' => 'Chicago', 'name' => 'Bulls', 'abbreviation' => 'CHI']);
    $gameA = Game::factory()->create([
        'home_team_id' => $homeA->id,
        'away_team_id' => $awayA->id,
        'game_date' => now()->copy()->startOfDay(),
        'game_time' => '18:00:00',
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => makeDailyDigestOddsData(
            homeTeam: 'New York Knicks',
            awayTeam: 'Chicago Bulls',
            homeSpread: -2.5,
            total: 219.5,
            homeMoneyline: -140,
            awayMoneyline: 120,
        ),
    ]);
    Prediction::query()->create([
        'game_id' => $gameA->id,
        'predicted_spread' => -5.5,
        'predicted_total' => 222.0,
        'win_probability' => 0.60,
        'confidence_score' => 68.0,
    ]);

    $homeB = Team::factory()->create(['location' => 'Dallas', 'name' => 'Mavericks', 'abbreviation' => 'DAL']);
    $awayB = Team::factory()->create(['location' => 'San Antonio', 'name' => 'Spurs', 'abbreviation' => 'SAS']);
    $gameB = Game::factory()->create([
        'home_team_id' => $homeB->id,
        'away_team_id' => $awayB->id,
        'game_date' => now()->copy()->startOfDay(),
        'game_time' => '20:30:00',
        'status' => 'STATUS_SCHEDULED',
        'odds_data' => makeDailyDigestOddsData(
            homeTeam: 'Dallas Mavericks',
            awayTeam: 'San Antonio Spurs',
            homeSpread: -1.5,
            total: 230.5,
            homeMoneyline: -120,
            awayMoneyline: 100,
        ),
    ]);
    Prediction::query()->create([
        'game_id' => $gameB->id,
        'predicted_spread' => -7.5,
        'predicted_total' => 236.5,
        'win_probability' => 0.70,
        'confidence_score' => 80.0,
    ]);

    $payload = app(DailyDigestService::class)->buildDigestForUser($user, now());

    expect($payload)->not->toBeNull();
    expect($payload['predictions'])->toHaveCount(2);
    expect($payload['predictions'][0]['matchup'])->toBe('SAS @ DAL');
    expect($payload['predictions'][0]['edge'])->toBeGreaterThan($payload['predictions'][1]['edge']);
});
