<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use App\Models\User;
use App\Services\NFL\NflPredictionBoardContext;
use App\Services\NFL\Research\ResearchPipeline;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function boardFixture(): array
{
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDay()->toDateString(), 'odds_data' => [
        'home_team' => 'Home', 'away_team' => 'Away', 'bookmakers' => [['key' => 'draftkings', 'last_update' => now()->toIso8601String(), 'markets' => [
            ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => -8.5, 'price' => -110], ['name' => 'Away', 'point' => 8.5, 'price' => -110]]],
            ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 42.5, 'price' => -110], ['name' => 'Under', 'point' => 42.5, 'price' => -110]]],
        ]]],
    ]]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 10.8, 'predicted_total' => 40.4, 'win_probability' => .74, 'updated_at' => now()->subHour()]);
    $report = SportsGameContextReport::create(['sport' => 'nfl', 'game_id' => $game->id, 'status' => 'ready', 'prompt_version' => 'test', 'input_hash' => str_repeat('a', 64), 'researched_at' => now(), 'expires_at' => now()->addHour()]);
    $revision = ResearchRevision::create(['game_id' => $game->id, 'report_id' => $report->id, 'input_hash' => str_repeat('b', 64), 'baseline' => [], 'revised' => ['win_probability' => .742, 'predicted_spread' => 10.8, 'predicted_total' => 40.4], 'evidence' => [], 'brief' => ['eligibility' => ['status' => 'pass', 'data_complete' => true, 'data_reasons' => []]], 'market' => [], 'created_at' => now()->subMinute()]);

    return [$prediction->load('game'), $report, $revision];
}

it('presents the latest research forecast separately from correctly sided fresh market lines', function () {
    [$prediction] = boardFixture();
    $v = app(NflPredictionBoardContext::class)->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($v['forecast']['win_probability'])->toBe(.742)
        ->and($v['research']['status'])->toBe('reviewed')
        ->and($v['research']['decision'])->toBe('pass')
        ->and($v['market']['home_spread'])->toBe(-8.5)
        ->and($v['market']['total'])->toBe(42.5);
});

it('does not call held, expired or superseded research ready', function () {
    [$prediction, $report, $revision] = boardFixture();
    $service = app(NflPredictionBoardContext::class);
    $revision->update(['brief' => ['eligibility' => ['status' => 'hold', 'data_complete' => false, 'data_reasons' => ['research_candidate_changed']]]]);
    expect($service->forPredictions(collect([$prediction]))->get($prediction->id)['research']['status'])->toBe('prediction_changed');
    $report->update(['expires_at' => now()->subMinute()]);
    expect($service->forPredictions(collect([$prediction]))->get($prediction->id)['research']['status'])->toBe('evidence_expired');
    $report->update(['expires_at' => now()->addHour()]);
    $prediction->updated_at = now();
    $v = $service->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($v['research']['status'])->toBe('prediction_changed')->and($v['forecast'])->toBeNull();
});

it('ignores timestamp only saves but detects changed model outputs and distinguishes missing snapshots', function () {
    [$prediction, , $revision] = boardFixture();
    $service = app(NflPredictionBoardContext::class);
    $prediction->updated_at = now();
    expect($service->forPredictions(collect([$prediction]))->get($prediction->id)['research']['status'])->toBe('reassessment_due');
    $revision->update(['brief' => [...$revision->brief, 'stored_prediction_hash' => app(ResearchPipeline::class)->storedPredictionHash($prediction->toArray())]]);
    expect($service->forPredictions(collect([$prediction]))->get($prediction->id)['research']['status'])->toBe('reviewed');
    $prediction->predicted_spread = 4.0;
    $result = $service->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($result['research']['status'])->toBe('prediction_changed')
        ->and($result['research']['prediction_changed'])->toBeTrue()
        ->and($result['forecast'])->toBeNull();
});

it('shows blocked refresh and evidence older than 36 hours independently near kickoff', function () {
    [$prediction, $report, $revision] = boardFixture();
    $kickoff = now()->utc()->addHours(4);
    $prediction->game->game_date = $kickoff->toDateString();
    $prediction->game->game_time = $kickoff->format('H:i:s');
    $report->update(['researched_at' => now()->subHours(37), 'expires_at' => now()->addHour()]);
    $revision->update(['brief' => [...$revision->brief, 'research_refresh' => ['deferred_reason' => 'research_game_attempt_limit_reached']]]);
    $result = app(NflPredictionBoardContext::class)->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($result['research']['status'])->toBe('refresh_blocked')
        ->and($result['research']['evidence_expired'])->toBeTrue()
        ->and($result['research']['reasons'])->toContain('research_evidence_expired', 'research_game_attempt_limit_reached')
        ->and($result['forecast'])->toBeNull();
});

it('does not reuse current odds to describe a final pregame prediction', function () {
    [$prediction] = boardFixture();
    $prediction->game->status = 'STATUS_FINAL';
    $v = app(NflPredictionBoardContext::class)->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($v['research']['status'])->toBe('archived')->and($v['forecast'])->toBeNull()
        ->and($v['market']['historical'])->toBeTrue()->and($v['market']['home_spread'])->toBeNull();
});

it('displays stored stale lines without making them eligible for research approval', function () {
    [$prediction, $report, $revision] = boardFixture();
    $revision->delete();
    $report->delete();
    $odds = $prediction->game->odds_data;
    $odds['bookmakers'][0]['last_update'] = now()->subHours(37)->toIso8601String();
    $prediction->game->odds_data = $odds;
    $v = app(NflPredictionBoardContext::class)->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($v['research']['status'])->toBe('missing')->and($v['forecast'])->toBeNull()
        ->and($v['market']['home_spread'])->toBe(-8.5)
        ->and($v['market']['total'])->toBe(42.5)
        ->and($v['market']['spread_stale'])->toBeTrue()
        ->and($v['market']['total_stale'])->toBeTrue()
        ->and($v['market']['observed_at'])->toBe($odds['bookmakers'][0]['last_update'])
        ->and(app(ResearchPipeline::class)->quotes($prediction->game))->toBe([])
        ->and(app(ResearchPipeline::class)->additionalMarketQuotes($prediction->game, 'totals'))->toBe([])
        ->and(app(ResearchPipeline::class)->marketIsFresh($prediction->game))->toBeFalse();
});

it('loads slate research in two batched queries, without per-game lookups or provider calls', function () {
    [$first] = boardFixture();
    [$second] = boardFixture();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(NflPredictionBoardContext::class)->forPredictions(collect([$first, $second]));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($result)->toHaveCount(2)->and($queries)->toHaveCount(2);
});

it('publishes compact board context and absolute kickoff through the prediction endpoint', function () {
    [$prediction] = boardFixture();
    $user = User::factory()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
    $this->getJson('/api/v2/sports/nfl/predictions/'.$prediction->id)
        ->assertOk()
        ->assertJsonPath('data.nfl_board.research.status', 'reviewed')
        ->assertJsonPath('data.nfl_board.forecast.win_probability', .742)
        ->assertJsonPath('data.nfl_board.market.home_spread', -8.5)
        ->assertJsonPath('data.game.kickoff_at', app(SportsDateWindowService::class)->gameDateTimeUtc($prediction->game->game_date, $prediction->game->game_time)->toIso8601String());
});

it('still rejects invalid stored market evidence on the board', function (string $failure) {
    [$prediction] = boardFixture();
    $odds = $prediction->game->odds_data;
    $odds['bookmakers'][0]['last_update'] = match ($failure) {
        'future' => now()->addHour()->toIso8601String(),
        'invalid timestamp' => 'not-a-date',
        default => now()->subHours(37)->toIso8601String(),
    };
    if ($failure === 'unpaired') {
        $odds['bookmakers'][0]['markets'][0]['outcomes'][1]['point'] = 7;
    }
    $prediction->game->odds_data = $odds;
    $v = app(NflPredictionBoardContext::class)->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($v['market']['home_spread'])->toBeNull();
})->with(['future', 'invalid timestamp', 'unpaired']);

it('prefers fresh market evidence to a stale preferred bookmaker', function () {
    [$prediction] = boardFixture();
    $odds = $prediction->game->odds_data;
    $odds['bookmakers'][1] = $odds['bookmakers'][0];
    $odds['bookmakers'][1]['key'] = 'fanduel';
    $odds['bookmakers'][0]['last_update'] = now()->subHours(37)->toIso8601String();
    $prediction->game->odds_data = $odds;
    $v = app(NflPredictionBoardContext::class)->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($v['market']['bookmaker'])->toBe('fanduel')
        ->and($v['market']['spread_stale'])->toBeFalse()
        ->and($v['market']['total_stale'])->toBeFalse();
});

it('marks lines stale only when older than 36 hours', function (int $ageSeconds, bool $stale) {
    $this->travelTo(now()->startOfSecond());
    [$prediction] = boardFixture();
    $odds = $prediction->game->odds_data;
    $odds['bookmakers'][0]['last_update'] = now()->subSeconds($ageSeconds)->toIso8601String();
    $prediction->game->odds_data = $odds;
    $pipeline = app(ResearchPipeline::class);
    $v = app(NflPredictionBoardContext::class)->forPredictions(collect([$prediction]))->get($prediction->id);
    expect($v['market']['home_spread'])->toBe(-8.5)
        ->and($v['market']['total'])->toBe(42.5)
        ->and($v['market']['spread_stale'])->toBe($stale)
        ->and($v['market']['total_stale'])->toBe($stale)
        ->and($pipeline->marketIsFresh($prediction->game))->toBe(! $stale)
        ->and(count($pipeline->additionalMarketQuotes($prediction->game, 'totals')))->toBe($stale ? 0 : 2);
})->with([
    'two hours is fresh' => [7200, false],
    'just under 36 hours' => [129599, false],
    'exactly 36 hours' => [129600, false],
    'older than 36 hours' => [129601, true],
]);

it('exposes the retry action only to admins for pregame predictions', function (bool $admin, bool $started, bool $allowed) {
    [$prediction] = boardFixture();
    $user = User::factory()->create(['is_admin' => $admin]);
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
    if ($started) {
        $prediction->game->update(['status' => 'STATUS_IN_PROGRESS']);
    }
    $this->getJson('/api/v2/sports/nfl/predictions/'.$prediction->id)
        ->assertOk()->assertJsonPath('data.nfl_board.can_retry_research', $allowed);
})->with([[false, false, false], [true, false, true], [true, true, false]]);
