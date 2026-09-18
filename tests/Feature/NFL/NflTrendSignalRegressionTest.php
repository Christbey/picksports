<?php

use App\Actions\Trends\Collectors\AdvancedTrendCollector;
use App\Actions\Trends\Collectors\DriveEfficiencyTrendCollector;
use App\Actions\Trends\Collectors\OffensiveEfficiencyTrendCollector;
use App\Actions\Trends\Collectors\OpponentStrengthTrendCollector;
use App\Actions\Trends\Collectors\QuarterTrendCollector;
use App\Actions\Trends\Collectors\RestScheduleTrendCollector;
use App\Actions\Trends\Collectors\SituationalTrendCollector;
use App\Actions\Trends\Collectors\StreakTrendCollector;
use App\Actions\Trends\Collectors\TimeBasedTrendCollector;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Services\NFL\NflBettingSignalService;
use App\Services\NFL\NflProSignalLayer;
use App\Services\Trends\TrendSignalScorer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function trendSignalFixture(int $count = 5): array
{
    $home = new Team;
    $home->forceFill(['id' => 1, 'abbreviation' => 'HOM']);
    $away = new Team;
    $away->forceFill(['id' => 2, 'abbreviation' => 'AWY']);
    $games = collect();
    for ($i = 0; $i < $count; $i++) {
        $game = new Game;
        $game->forceFill(['id' => $i + 1, 'home_team_id' => 1, 'away_team_id' => 2,
            'game_date' => Carbon::parse('2026-08-01')->addDays(14 * $i),
            'home_score' => 24, 'away_score' => 21, 'game_time' => '00:15:00']);
        $game->setRelation('prediction', new Prediction(['predicted_spread' => 7, 'home_elo' => 1600, 'away_elo' => 1600]));
        $game->setRelation('homeTeam', $home)->setRelation('awayTeam', $away);
        $game->setRelation('teamStats', collect([new TeamStat(['team_id' => 1, 'total_yards' => 400,
            'passing_attempts' => 35, 'rushing_attempts' => 25, 'sacks_allowed' => 4,
            'third_down_attempts' => 10, 'third_down_conversions' => 6])]));
        $games->push($game);
    }

    return [$home, $away, $games];
}

it('uses the NFL margin convention for favorites and model results on both sides', function () {
    [$home, $away, $games] = trendSignalFixture();
    $homeAdvanced = implode(' ', (new AdvancedTrendCollector)->setContext('nfl', $home, $games)->collect());
    $awayAdvanced = implode(' ', (new AdvancedTrendCollector)->setContext('nfl', $away, $games)->collect());
    expect($homeAdvanced)->toContain('big favorites', '0-5 (0%) against the model spread')->not->toContain('big underdogs')
        ->and($awayAdvanced)->toContain('big underdogs', '5-0 (100%) against the model spread');
    expect(implode(' ', (new SituationalTrendCollector)->setContext('nfl', $home, $games)->collect()))
        ->toContain('made them favorites')->not->toContain('made them underdogs');
    expect(implode(' ', (new StreakTrendCollector)->setContext('nfl', $home, $games)->collect()))->toContain('below-model-margin')->not->toContain('ATS covers');
    expect(implode(' ', (new StreakTrendCollector)->setContext('nfl', $away, $games)->collect()))->toContain('above-model-margin');
});

it('keeps model pushes and tied games out of losing streaks', function () {
    [$home, , $games] = trendSignalFixture();
    foreach ($games as $game) {
        $game->home_score = 21;
        $game->prediction->predicted_spread = 0;
    }
    expect(implode(' ', (new StreakTrendCollector)->setContext('nfl', $home, $games)->collect()))
        ->toContain('0-0-5')->not->toContain('losing streak', 'below-model-margin');
    expect(implode(' ', (new AdvancedTrendCollector)->setContext('nfl', $home, $games)->collect()))->toContain('0-0-5');
});

it('uses positive rest intervals and the historical kickoff date for DST', function () {
    [$home, , $games] = trendSignalFixture();
    expect(implode(' ', (new RestScheduleTrendCollector)->setContext('nfl', $home, $games)->collect()))
        ->toContain('extended rest')->not->toContain('short rest');
    Carbon::setTestNow('2026-09-17');
    try {
        $collector = (new TimeBasedTrendCollector)->setContext('nfl', $home, $games);
        $games[0]->game_date = '2025-12-15';
        expect((new ReflectionMethod($collector, 'getGameHour'))->invoke($collector, $games[0]))->toBe(19);
    } finally {
        Carbon::setTestNow();
    }
});

it('uses real NFL stat fields and does not invent zero turnovers', function () {
    [$home, , $games] = trendSignalFixture();
    expect(implode(' ', (new DriveEfficiencyTrendCollector)->setContext('nfl', $home, $games)->collect()))
        ->toContain('6.25 yards per play');
    expect(implode(' ', (new OffensiveEfficiencyTrendCollector)->setContext('nfl', $home, $games)->collect()))
        ->not->toContain('fewer turnovers');
    expect(implode(' ', (new OpponentStrengthTrendCollector)->setContext('nfl', $home, $games)->collect()))
        ->toContain('pregame Elo 1550+');
});

it('excludes sparse NFL quarters instead of renumbering or filling missing periods', function () {
    [$home, , $games] = trendSignalFixture();
    foreach ($games as $game) {
        $game->home_linescores = [['period' => 1, 'value' => 7], ['period' => 3, 'value' => 14]];
        $game->away_linescores = [0, 7, 7, 7];
    }
    expect((new QuarterTrendCollector)->setContext('nfl', $home, $games)->collect())->toBe([]);
    $games[0]->home_linescores = [7, 7, 7, 3];
    expect(implode(' ', (new QuarterTrendCollector)->setContext('nfl', $home, $games)->collect()))
        ->toContain('in 1 of their last 1 games');
});

it('ranks occurrence ratios rather than embedded efficiency thresholds', function () {
    $signals = app(TrendSignalScorer::class)->score('nfl', ['drive_efficiency' => [
        'Converted 40%+ of 3rd downs in 8 of 10 games',
        'Strong red zone efficiency (75%+) in 3 of 10 games',
    ]], 10);
    expect(collect($signals)->keyBy('id')['drive_efficiency_0']['percentage'])->toBe(80.0)
        ->and(collect($signals)->keyBy('id')['drive_efficiency_1']['percentage'])->toBe(30.0);
});

it('selects the next scheduled week and never recommends completed or live games', function () {
    $asOf = Carbon::parse('2026-09-18 02:00:00', 'UTC');
    $ids = [];
    $teams = Team::factory()->count(2)->create();
    foreach ([['STATUS_FINAL', 1, '2026-09-13'], ['STATUS_IN_PROGRESS', 2, '2026-09-18'],
        ['STATUS_SCHEDULED', 2, '2026-09-20'], ['STATUS_SCHEDULED', 3, '2026-09-27']] as [$status, $week, $date]) {
        $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
            'season' => 2026, 'season_type' => '2', 'status' => $status,
            'week' => $week, 'game_date' => $date, 'game_time' => '17:00:00']);
        Prediction::factory()->create(['game_id' => $game->id]);
        $ids[$week] = $game->id;
    }
    $service = app(NflBettingSignalService::class);
    $rows = (new ReflectionMethod($service, 'slatePredictionRows'))->invoke($service, 2026, $asOf);
    expect(collect($rows)->pluck('game_id')->all())->toBe([$ids[2]]);
    $panelService = Mockery::mock(NflBettingSignalService::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $panelService->shouldReceive('superBowlSignals')->andReturn([]);
    $payload = $panelService->signals(2026, $asOf);
    expect((int) $payload['week'])->toBe(2)
        ->and($payload['winners'])->toHaveCount(1)
        ->and($payload['week_one_winners'])->toBe([])
        ->and($payload['week_one_covers'])->toBe([]);
});

it('selects spread and total sides from their edges and separates watchlists', function () {
    [$home, , $games] = trendSignalFixture(1);
    $prediction = $games[0]->prediction;
    $prediction->win_probability = .8;
    $prediction->setRelation('game', $games[0]);
    $analysis = ['bet_classification' => 'bet', 'calculated_edge' => [
        'market_spread' => 10, 'market_total' => 45, 'spread_points' => -3, 'total_points' => 1,
    ]];
    $prediction->model_metadata = ['analysis_layer' => $analysis];
    $service = app(NflBettingSignalService::class);
    $method = new ReflectionMethod($service, 'recommendedBets');
    expect($method->invoke($service, [$prediction])[0])->toMatchArray(['type' => 'spread', 'pick_side' => 'away', 'edge_points' => 3.0]);
    $analysis['calculated_edge']['total_points'] = -6;
    $prediction->model_metadata = ['analysis_layer' => $analysis];
    expect($method->invoke($service, [$prediction])[0])->toMatchArray(['type' => 'total', 'pick_side' => 'under', 'edge_points' => 6.0]);
    $analysis['bet_classification'] = 'lean';
    $prediction->model_metadata = ['analysis_layer' => $analysis];
    expect($method->invoke($service, [$prediction]))->toBe([])
        ->and($method->invoke($service, [$prediction], true))->toHaveCount(1);
});

it('breaks market streaks at pushes and loads team histories once for all markets', function () {
    [$home, , $games] = trendSignalFixture(1);
    $games[0]->prediction->model_metadata = ['analysis_layer' => ['calculated_edge' => ['market_spread' => 3, 'market_total' => 45]]];
    $service = app(NflBettingSignalService::class);
    foreach (['atsResult' => [1], 'totalResult' => []] as $method => $args) {
        expect((new ReflectionMethod($service, $method))->invoke($service, $games[0], ...$args))->toBeNull();
    }
    $teams = Team::factory()->count(4)->create();
    for ($i = 0; $i < 4; $i++) {
        $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
            'season' => 2026, 'season_type' => $i === 3 ? '1' : '2', 'status' => 'STATUS_FINAL',
            'home_score' => 24, 'away_score' => 21, 'game_date' => '2026-09-'.(10 + $i)]);
        Prediction::factory()->create(['game_id' => $game->id, 'model_metadata' => [
            'analysis_layer' => ['calculated_edge' => ['market_spread' => 2.5, 'market_total' => 44.5]],
        ]]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $rows = (new ReflectionMethod($service, 'streakSignals'))->invoke($service, 2026, Carbon::parse('2026-09-18'));
        expect(DB::getQueryLog())->toHaveCount(3);
        expect(collect($rows)->where('type', 'straight_up_streak')->pluck('length')->all())->each->toBe(3);
        expect(collect($rows)->where('type', 'ats_streak'))->toHaveCount(2)
            ->and(collect($rows)->where('type', 'total_streak')->pluck('length')->all())->toBe([3, 3]);
    } finally {
        DB::disableQueryLog();
    }
});

it('gives symmetric winner strength and correct signed CLV and key crossings', function () {
    $service = app(NflProSignalLayer::class);
    $method = new ReflectionMethod($service, 'marketScores');
    $scores = [];
    foreach ([.8, .2] as $probability) {
        $scores[] = $method->invokeArgs($service, [$probability, null, null, [], [], false, [], [], [], [], [], [], [], [], []])['winner']['score'];
    }
    expect($scores[0])->toBe($scores[1]);
    $clv = new ReflectionMethod($service, 'spreadClv');
    expect($clv->invoke($service, 'home', 3, 7))->toBe(4.0)
        ->and($clv->invoke($service, 'away', 3, 7))->toBe(-4.0);
    expect((new ReflectionMethod($service, 'crossedKeyNumbers'))->invoke($service, -4, 4, [3, 5, 7, 10]))->toBe([3]);
});
