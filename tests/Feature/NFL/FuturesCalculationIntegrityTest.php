<?php

use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\Sports\FuturesOdd;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportForecastQuery;
use App\Services\NFL\NflPlayoffScoringHistory;
use App\Services\NFL\NflPlayoffTeamInputs;
use App\Services\NFL\TeamFuturesProjectionService;
use App\Services\NFL\TeamPlayoffForecastService;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/NflFutures.php';

it('conserves league wins, locks completed results and ties, and conditions seeds on qualification', function () {
    $this->travelTo('2026-10-02');
    completeNflFuturesSchedule(2026, '2026-01-01T00:00:00Z');
    $games = Game::query()->orderBy('id')->get();
    $wins = [];
    $played = [];
    foreach ($games->take(48) as $index => $game) {
        $game->update(['status' => 'STATUS_FINAL', 'home_score' => 21, 'away_score' => $index === 0 ? 21 : 7, 'game_date' => '2026-10-01']);
        foreach ([$game->home_team_id, $game->away_team_id] as $id) {
            $played[$id] = ($played[$id] ?? 0) + 1;
        }
        if ($index !== 0) {
            $wins[$game->home_team_id] = ($wins[$game->home_team_id] ?? 0) + 1;
        }
    }
    $service = app(TeamPlayoffForecastService::class);
    mt_srand(837);
    $expectedRandomDraw = mt_rand();
    mt_srand(837);
    $report = $service->forecast(2026, '2026-12-31', true, 500, 42);
    expect(mt_rand())->toBe($expectedRandomDraw)
        ->and($report['teams'])->toBe($service->forecast(2026, '2026-12-31', true, 500, 42)['teams'])
        ->and($report['tie_model']['sample_games'])->toBe(48)
        ->and($report['tie_model']['sample_ties'])->toBe(1)
        ->and($report['tie_model']['tie_probability'])->toBe(1 / 48);
    $teams = collect($report['teams']);
    $expectedTiedGames = 1 + (272 - 48) / 48;
    expect($teams)->toHaveCount(32)
        ->and($teams->sum('projected_ties') / 2)->toEqualWithDelta($expectedTiedGames, 0.3)
        ->and($teams->sum('projected_wins') + $teams->sum('projected_ties') / 2)->toEqualWithDelta(272, 0.03)
        ->and(abs($teams->sum('make_playoffs_probability') - 14))->toBeLessThan(0.002)
        ->and(abs($teams->sum('division_winner_probability') - 8))->toBeLessThan(0.002)
        ->and(abs($teams->sum('conference_champion_probability') - 2))->toBeLessThan(0.002)
        ->and(abs($teams->sum('super_bowl_champion_probability') - 1))->toBeLessThan(0.002);
    foreach ($teams as $team) {
        $id = $team['team_id'];
        expect($team['projected_wins'])->toBeGreaterThanOrEqual($wins[$id] ?? 0)
            ->toBeLessThanOrEqual(($wins[$id] ?? 0) + 17 - ($played[$id] ?? 0));
        if ($team['make_playoffs_probability'] > 0) {
            expect($team['projected_seed'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(7);
        } else {
            expect($team['projected_seed'])->toBeNull();
        }
        expect($team['super_bowl_champion_probability'])->toBeLessThanOrEqual($team['conference_champion_probability']);
    }
    // Once every game is complete, forecast wins must match the actual record exactly.
    Game::query()->where('status', 'STATUS_SCHEDULED')->update(['status' => 'STATUS_FINAL', 'home_score' => 14, 'away_score' => 7, 'game_date' => '2026-10-01']);
    $final = $service->forecast(2026, '2026-12-31', true, 100, 7);
    expect(array_sum(array_column($final['teams'], 'projected_ties')))->toEqual(2);
    foreach ($final['teams'] as $team) {
        $actual = Game::query()->where('home_team_id', $team['team_id'])->whereColumn('home_score', '>', 'away_score')->count();
        expect($team['projected_wins'])->toEqual($actual);
    }
});

it('does not leak later completed results into historical simulations', function () {
    completeNflFuturesSchedule(2026, '2026-01-01T00:00:00Z');
    $service = app(TeamPlayoffForecastService::class);
    $before = $service->forecast(2026, '2026-08-01', true, 100, 7);
    expect($before['tie_model']['tie_probability'])->toBeNull()
        ->and(array_sum(array_column($before['teams'], 'projected_ties')))->toEqual(0);
    Game::query()->update(['status' => 'STATUS_FINAL', 'home_score' => 99, 'away_score' => 0, 'game_date' => '2026-10-01']);
    expect($service->forecast(2026, '2026-08-01', true, 100, 7)['teams'])->toBe($before['teams']);
});

it('uses available historical final scores and reconciles touchdowns before sampling', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $this->travelTo('2025-10-01 18:30:00');
    $history = Game::factory()->create([
        'season' => 2025,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2025-10-01 15:00:00',
        'status' => config('nfl.statuses.final'),
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 7,
        'away_score' => 17,
    ]);

    foreach ([[0, 7], [7, 7], [7, 10]] as $index => [$homeScore, $awayScore]) {
        Play::factory()->create([
            'game_id' => $history->id,
            'sequence_number' => $index + 1,
            'is_scoring_play' => true,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ]);
    }

    $this->travelTo('2025-10-01 20:00:00');
    Play::factory()->create([
        'game_id' => $history->id,
        'sequence_number' => 4,
        'is_scoring_play' => true,
        'home_score' => 7,
        'away_score' => 17,
    ]);

    $scoring = app(NflPlayoffScoringHistory::class);
    $schedule = [['completed' => false]];
    expect($scoring->load(2026, now()->parse('2025-10-01 18:00:00'), $schedule)['samples'])->toBe([])
        ->and($scoring->load(2026, now()->parse('2025-10-01 19:00:00'), $schedule)['samples'])->toBe([
            ['points' => [17, 7], 'touchdowns' => null, 'tied' => false],
        ])
        ->and($scoring->load(2026, now(), $schedule)['samples'])->toBe([
            ['points' => [17, 7], 'touchdowns' => [2, 1], 'tied' => false],
        ]);

    $this->travelTo('2025-10-08 18:30:00');
    foreach ([config('nfl.season.types.regular'), config('nfl.season.types.postseason')] as $seasonType) {
        Game::factory()->create([
            'season' => 2025,
            'season_type' => $seasonType,
            'game_date' => '2025-10-08 15:00:00',
            'status' => config('nfl.statuses.final'),
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 14,
            'away_score' => 14,
        ]);
    }

    $beforeTie = $scoring->load(2026, now()->parse('2025-10-08 18:29:59'), $schedule);
    $afterTie = $scoring->load(2026, now(), $schedule);
    expect($beforeTie['tie_model']['tie_probability'])->toEqual(0.0)
        ->and($afterTie['tie_model'])->toBe(['sample_games' => 2, 'sample_ties' => 1, 'tie_probability' => 0.5])
        ->and($afterTie['samples'][1])->toBe(['points' => [14, 14], 'touchdowns' => null, 'tied' => true]);
});

it('withholds probabilities when the schedule is incomplete', function () {
    completeNflFuturesSchedule(2026, '2026-01-01');
    Game::query()->first()->delete();
    $report = app(TeamPlayoffForecastService::class)->forecast(2026, '2026-08-01', true, 100);
    expect($report['teams'])->toBeEmpty()->and($report['warnings'])->not->toBeEmpty();
});

it('loads playoff team ratings with the shared alignment fallback', function () {
    config()->set('nfl.team_futures.prior_games', 4);
    $team = Team::factory()->create(['abbreviation' => 'WAS', 'conference' => null, 'division' => null]);
    TeamMetricSnapshot::query()->create([
        'snapshot_key' => sha1('playoff-team-inputs'),
        'team_id' => $team->id,
        'season' => 2026,
        'wins' => 3,
        'losses' => 1,
        'predictive_rating' => 1550,
        'calculation_date' => '2026-10-01',
        'captured_at' => '2026-10-01 12:00:00',
    ]);

    $inputs = app(NflPlayoffTeamInputs::class);
    expect($inputs->load(2026, now()->parse('2026-10-01 11:59:59'), false))->toBe([]);

    [$row] = $inputs->load(2026, now()->parse('2026-10-01 12:00:00'), false);
    expect($row['team_id'])->toBe($team->id)
        ->and($row['conference'])->toBe('NFC')
        ->and($row['division'])->toBe('East')
        ->and($row['strength_rating'])->toBe(0.125);
});

it('conditions playoff brackets on postseason matchups and results only when available at the cutoff', function () {
    $this->travelTo('2027-01-10 12:00:00');
    completeNflFuturesSchedule(2026, '2026-01-01');
    Game::query()->update([
        'status' => config('nfl.statuses.final'),
        'game_date' => '2027-01-09 18:00:00',
        'home_score' => 21,
        'away_score' => 7,
        'result_updated_at' => now(),
    ]);

    $service = app(TeamPlayoffForecastService::class);
    $before = $service->forecast(2026, '2027-02-14 22:00:00', true, 500, 89);
    $champions = array_column($before['conference_leaders'], 'team_id', 'conference');
    $afc = $champions['AFC'];
    $nfc = $champions['NFC'];
    $this->travelTo('2027-02-14 17:00:00');
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('nfl.season.types.postseason'),
        'status' => config('nfl.statuses.scheduled'),
        'game_date' => '2027-02-14 18:00:00',
        'home_team_id' => $afc,
        'away_team_id' => $nfc,
        'neutral_site' => true,
        'home_score' => null,
        'away_score' => null,
    ]);

    expect($service->forecast(2026, '2027-02-14 16:59:59', true, 500, 89)['teams'])->toBe($before['teams']);
    $scheduled = $service->forecast(2026, '2027-02-14 17:00:00', true, 500, 89);
    $scheduledTeams = array_column($scheduled['teams'], null, 'team_id');
    expect($scheduled['summary']['scheduled_postseason_games'])->toBe(1)
        ->and($scheduled['summary']['completed_postseason_games'])->toBe(0)
        ->and($scheduledTeams[$afc]['conference_champion_probability'])->toEqual(1)
        ->and($scheduledTeams[$nfc]['conference_champion_probability'])->toEqual(1)
        ->and($scheduledTeams[$afc]['super_bowl_champion_probability'])->toBeGreaterThan(0)->toBeLessThan(1);

    $this->travelTo('2027-02-14 22:30:00');
    $game->update([
        'status' => config('nfl.statuses.final'),
        'home_score' => 17,
        'away_score' => 21,
    ]);

    expect($service->forecast(2026, '2027-02-14 22:00:00', true, 500, 89)['teams'])->toBe($before['teams']);

    $after = $service->forecast(2026, '2027-02-14 22:30:00', true, 500, 89);
    $teams = array_column($after['teams'], null, 'team_id');
    expect($after['summary']['completed_postseason_games'])->toBe(1)
        ->and($after['summary']['simulations'])->toBeGreaterThan(0)->toBeLessThan(500)
        ->and($teams[$afc]['conference_champion_probability'])->toEqual(1)
        ->and($teams[$afc]['super_bowl_champion_probability'])->toEqual(0)
        ->and($teams[$nfc]['conference_champion_probability'])->toEqual(1)
        ->and($teams[$nfc]['super_bowl_champion_probability'])->toEqual(1);
});

it('does not convert a scoring-total injury penalty into a win-strength penalty', function () {
    completeNflFuturesSchedule(2026, '2026-01-01');
    $service = app(TeamFuturesProjectionService::class);
    $before = $service->projections(2026, asOfDate: '2026-08-01', requireHistoricalMetrics: true);
    expect($before)->toHaveCount(32)
        ->and(array_values(array_unique(array_column(array_column($before, 'metric_evidence'), 'mode'))))->toBe(['current_capture']);
    TeamMetricSnapshot::query()->update(['injury_total_adjustment' => -10]);
    $after = $service->projections(2026, asOfDate: '2026-08-01', requireHistoricalMetrics: true);
    // This is the descriptive path; the changed receipt is unverified rather than a newly observed capture.
    expect($after)->toHaveCount(32)
        ->and(array_values(array_unique(array_column(array_column($after, 'metric_evidence'), 'mode'))))->toBe(['legacy_unverified']);
    expect(array_column($after, 'projected_total'))->toBe(array_column($before, 'projected_total'));
});

it('retains stored futures prices but suppresses stale and future-dated comparisons', function (int $age, bool $stale) {
    Cache::flush();
    $this->travelTo(now()->startOfSecond());
    $this->mock(TeamPlayoffForecastService::class)->shouldReceive('forecast')->once()->andReturn([
        'summary' => ['simulations' => 5000],
        'teams' => [['team_id' => 1, 'team_name' => 'Test', 'super_bowl_champion_probability' => 0.25]],
    ]);
    $team = Team::factory()->create(['id' => 1]);
    FuturesOdd::query()->create([
        'row_key' => sha1('freshness'), 'sport' => 'nfl', 'season' => 2026,
        'nfl_team_id' => $team->id, 'bookmaker' => 'draftkings', 'market_key' => 'outrights',
        'odds_api_sport_key' => 'americanfootball_nfl_super_bowl_winner', 'outcome_name' => 'Test',
        'price' => 900, 'implied_probability' => 0.1, 'fetched_at' => now()->subSeconds($age),
    ]);
    $result = app(SportForecastQuery::class)->get(app(SportContextResolver::class)->resolve('nfl'), ['season' => 2026]);
    $row = $result['data'][0];
    expect($row['market_odds']['price'])->toBe(900)
        ->and($row['market_odds']['stale'])->toBe($stale)
        ->and($row['market_edge']['actionable'])->toBeFalse();
    if ($age === 36 * 3600) {
        $this->travel(1)->seconds();
        $cached = app(SportForecastQuery::class)->get(app(SportContextResolver::class)->resolve('nfl'), ['season' => 2026]);
        expect($cached['data'][0]['market_odds']['stale'])->toBeTrue()
            ->and($cached['data'][0]['market_edge']['edge_probability'])->toBeNull();
    }
    if ($stale) {
        expect($row['market_edge']['edge_probability'])->toBeNull()->and($row['market_edge']['has_edge'])->toBeFalse();
    } else {
        expect($row['market_edge']['edge_probability'])->toEqualWithDelta(0.15, 0.000001);
    }
})->with([[35 * 3600, false], [36 * 3600, false], [36 * 3600 + 1, true], [-3600, true]]);
