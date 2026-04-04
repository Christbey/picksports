<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetricSnapshot;
use App\Models\Sports\FuturesOddsSnapshot;
use App\Services\NFL\TeamFuturesProjectionService;

it('exposes offseason signal factors on preseason team futures projections', function () {
    $team = Team::factory()->create([
        'name' => 'Lions',
        'location' => 'Detroit',
        'abbreviation' => 'DET',
    ]);
    $opponent = Team::factory()->create([
        'name' => 'Bears',
        'location' => 'Chicago',
        'abbreviation' => 'CHI',
    ]);

    $qb = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'QB',
    ]);

    foreach ([2023, 2024] as $season) {
        $game = Game::factory()->create([
            'season' => $season,
            'season_type' => config('nfl.season.types.regular'),
            'game_date' => "{$season}-10-01 12:00:00",
            'status' => config('nfl.statuses.final'),
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'home_score' => 28,
            'away_score' => 14,
        ]);

        PlayerStat::query()->create([
            'player_id' => $qb->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'passing_attempts' => 32,
        ]);
    }

    PlayerInjury::query()->create([
        'player_id' => $qb->id,
        'team_id' => $team->id,
        'injury_key' => 'qb-questionable',
        'status' => 'Questionable',
        'injury_date' => '2025-07-30',
        'source_updated_at' => '2025-07-31 12:00:00',
        'is_active' => true,
    ]);

    TeamMetricSnapshot::query()->create([
        'snapshot_key' => sha1('offseason-snapshot'),
        'team_id' => $team->id,
        'season' => 2025,
        'wins' => 0,
        'losses' => 0,
        'predictive_rating' => 6.5,
        'future_strength_of_schedule' => 1498.0,
        'recent_form_rating' => 1.0,
        'injury_total_adjustment' => -0.35,
        'calculation_date' => '2025-08-01',
        'captured_at' => '2025-08-01T12:00:00Z',
    ]);

    foreach (['Over', 'Under'] as $side) {
        FuturesOddsSnapshot::query()->create([
            'snapshot_key' => sha1('offseason-'.$side),
            'row_key' => sha1('offseason-row-'.$side),
            'sport' => 'nfl',
            'season' => 2025,
            'odds_api_sport_key' => 'sportsoddshistory_nfl_team',
            'bookmaker' => 'sportsoddshistory',
            'market_key' => 'season_wins',
            'outcome_name' => $side,
            'outcome_description' => 'Lions',
            'outcome_point' => 9.5,
            'price' => -110,
            'implied_probability' => 0.5238,
            'captured_at' => '2025-08-01T12:00:00Z',
            'nfl_team_id' => $team->id,
        ]);
    }

    $rows = app(TeamFuturesProjectionService::class)->projections(
        season: 2025,
        market: 'season_wins',
        asOfDate: '2025-08-01T12:00:00Z',
        requireHistoricalMetrics: true,
        onlyWithOdds: true,
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['projection_factors']['offseason_adjustment'])->toBeGreaterThan(1.0)
        ->and($rows[0]['projection_factors']['qb_continuity_signal'])->toBeGreaterThan(0.9)
        ->and($rows[0]['projection_factors']['injury_total_adjustment'])->toBeLessThan(0.0);
});
