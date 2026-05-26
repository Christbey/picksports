<?php

use App\Actions\MLB\CalculateTeamTrends;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
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
        ->and($situational)->toContain('as favorites')
        ->and($league)->toContain('in league games')
        ->and($league)->toContain('in division games')
        ->and($league)->not->toContain('conference');
});
