<?php

use App\Actions\MLB\CalculateTeamTrends;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;
use Carbon\Carbon;

it('uses baseball-specific language for MLB scoring pattern and clutch trends', function () {
    $team = Team::factory()->create(['abbreviation' => 'NYY']);
    $opponent = Team::factory()->create(['abbreviation' => 'KC']);

    for ($i = 1; $i <= 6; $i++) {
        Game::factory()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'season_type' => config('mlb.season.types.regular'),
            'status' => 'STATUS_FINAL',
            'game_date' => Carbon::parse('2026-05-25')->subDays($i),
            'home_score' => 2,
            'away_score' => 3,
            'home_linescores' => [
                ['period' => 1, 'value' => 2],
                ['period' => 2, 'value' => 0],
                ['period' => 3, 'value' => 0],
                ['period' => 4, 'value' => 0],
                ['period' => 5, 'value' => 0],
                ['period' => 6, 'value' => 0],
                ['period' => 7, 'value' => 0],
                ['period' => 8, 'value' => 0],
                ['period' => 9, 'value' => 0],
            ],
            'away_linescores' => [
                ['period' => 1, 'value' => 0],
                ['period' => 2, 'value' => 0],
                ['period' => 3, 'value' => 0],
                ['period' => 4, 'value' => 0],
                ['period' => 5, 'value' => 0],
                ['period' => 6, 'value' => 1],
                ['period' => 7, 'value' => 1],
                ['period' => 8, 'value' => 1],
                ['period' => 9, 'value' => 0],
            ],
        ]);
    }

    $trends = app(CalculateTeamTrends::class)->execute(
        $team,
        gameCount: 0,
        season: 2026,
        seasonType: (string) config('mlb.season.types.regular'),
    )['trends'];

    $scoringPatterns = implode(' ', $trends['scoring_patterns'] ?? []);
    $clutch = implode(' ', $trends['clutch_performance'] ?? []);

    expect($scoringPatterns)->toContain('first-five-innings')
        ->and($scoringPatterns)->toContain('the 1st inning')
        ->and($scoringPatterns)->not->toContain('halftime')
        ->and($scoringPatterns)->not->toContain('Q1')
        ->and($clutch)->toContain('2 runs or less')
        ->and($clutch)->not->toContain('2 points or less');
});

it('loads model and league categories for MLB team trends', function () {
    $team = Team::factory()->create([
        'abbreviation' => 'NYY',
        'league' => null,
        'division' => null,
    ]);
    $opponent = Team::factory()->create([
        'abbreviation' => 'BOS',
        'league' => null,
        'division' => null,
    ]);

    for ($i = 1; $i <= 6; $i++) {
        $game = Game::factory()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'season_type' => config('mlb.season.types.regular'),
            'status' => 'STATUS_FINAL',
            'game_date' => Carbon::parse('2026-05-25')->subDays($i),
            'home_score' => 5,
            'away_score' => 4,
        ]);

        Prediction::query()->create([
            'game_id' => $game->id,
            'season' => 2026,
            'season_type' => (string) config('mlb.season.types.regular'),
            'home_team_elo' => 1520,
            'away_team_elo' => 1490,
            'home_pitcher_elo' => 1500,
            'away_pitcher_elo' => 1490,
            'home_combined_elo' => 1510,
            'away_combined_elo' => 1490,
            'predicted_spread' => -0.5,
            'predicted_total' => 8.5,
            'win_probability' => 0.56,
            'confidence_score' => 0.61,
            'model_version' => 'test',
        ]);
    }

    $trends = app(CalculateTeamTrends::class)->execute(
        $team,
        gameCount: 0,
        season: 2026,
        seasonType: (string) config('mlb.season.types.regular'),
    )['trends'];

    $advanced = implode(' ', $trends['advanced'] ?? []);
    $league = implode(' ', $trends['conference'] ?? []);
    $situational = implode(' ', $trends['situational'] ?? []);

    expect($advanced)->toContain('against the model spread')
        ->and($situational)->toContain('Picksports model made them favorites')
        ->and($league)->toContain('in league games')
        ->and($league)->toContain('in division games')
        ->and($league)->not->toContain('conference');
});

it('uses team stat runs when final game scores are missing', function () {
    $team = Team::factory()->create(['abbreviation' => 'ARI']);
    $opponent = Team::factory()->create(['abbreviation' => 'CLE']);

    for ($i = 1; $i <= 6; $i++) {
        $game = Game::factory()->regularSeason()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'status' => 'STATUS_FINAL',
            'game_date' => Carbon::parse('2026-06-20')->subDays($i),
            'home_score' => null,
            'away_score' => null,
        ]);

        TeamStat::factory()->create([
            'game_id' => $game->id,
            'team_id' => $team->id,
            'team_type' => 'home',
            'runs' => 5,
        ]);
        TeamStat::factory()->create([
            'game_id' => $game->id,
            'team_id' => $opponent->id,
            'team_type' => 'away',
            'runs' => 3,
        ]);
        Prediction::query()->create([
            'game_id' => $game->id,
            'season' => 2026,
            'season_type' => (string) config('mlb.season.types.regular'),
            'predicted_spread' => -1.0,
            'predicted_total' => 9.5,
            'win_probability' => 0.6,
            'confidence_score' => 60,
        ]);
    }

    $trends = app(CalculateTeamTrends::class)->execute(
        $team,
        gameCount: 0,
        season: 2026,
        seasonType: (string) config('mlb.season.types.regular'),
    )['trends'];

    expect(implode(' ', $trends['scoring'] ?? []))->toContain('average 5.0 runs per game')
        ->and(implode(' ', $trends['totals'] ?? []))->toContain('average 8.0 total runs')
        ->and(implode(' ', $trends['totals'] ?? []))->toContain('UNDER the model total in 6 of their last 6 games')
        ->and(implode(' ', $trends['defensive_performance'] ?? []))->toContain('allows 3.0 runs per game');
});

it('does not count tied or missing first innings as trailing', function () {
    $team = Team::factory()->create(['abbreviation' => 'ARI']);
    $opponent = Team::factory()->create(['abbreviation' => 'CLE']);
    $firstInnings = [[0, 1], [0, 2], [1, 2], [0, 0], [1, 1], [2, 0], null];

    foreach ($firstInnings as $index => $firstInning) {
        $teamWon = in_array($index, [0, 1, 5], true);
        Game::factory()->regularSeason()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'status' => 'STATUS_FINAL',
            'game_date' => Carbon::parse('2026-06-20')->subDays($index + 1),
            'home_score' => $teamWon ? 5 : 2,
            'away_score' => $teamWon ? 3 : 4,
            'home_linescores' => $firstInning === null ? null : [$firstInning[0]],
            'away_linescores' => $firstInning === null ? null : [$firstInning[1]],
        ]);
    }

    $trends = app(CalculateTeamTrends::class)->execute(
        $team,
        gameCount: 0,
        season: 2026,
        seasonType: (string) config('mlb.season.types.regular'),
    )['trends'];
    $firstScore = implode(' ', $trends['first_score'] ?? []);

    expect($firstScore)->toContain('trail after the 1st inning')
        ->and($firstScore)->toContain('(2/3)')
        ->and($firstScore)->not->toContain('/5)')
        ->and($firstScore)->not->toContain('/6)');
});

it('applies an exact before-game time for same-day trend samples', function () {
    $team = Team::factory()->create(['abbreviation' => 'ARI']);
    $opponent = Team::factory()->create(['abbreviation' => 'CLE']);

    foreach (['12:00:00', '18:00:00'] as $time) {
        Game::factory()->regularSeason()->create([
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'season' => 2026,
            'status' => 'STATUS_FINAL',
            'game_date' => '2026-06-20',
            'game_time' => $time,
            'home_score' => 5,
            'away_score' => 3,
        ]);
    }

    expect(app(CalculateTeamTrends::class)->countAvailableGames(
        $team,
        season: 2026,
        seasonType: (string) config('mlb.season.types.regular'),
        beforeDate: '2026-06-20T15:00:00',
    ))->toBe(1);
});
