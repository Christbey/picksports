<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\NFL\TeamOffseasonSignalService;

it('builds qb continuity, skill continuity, and injury signals from prior seasons', function () {
    $team = Team::factory()->create([
        'name' => 'Bills',
        'location' => 'Buffalo',
        'abbreviation' => 'BUF',
    ]);
    $opponent = Team::factory()->create([
        'name' => 'Jets',
        'location' => 'New York',
        'abbreviation' => 'NYJ',
    ]);

    $qb = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'QB',
        'full_name' => 'Stable QB',
    ]);
    $rb = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'RB',
        'full_name' => 'Returning RB',
    ]);
    $wr = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'WR',
        'full_name' => 'Returning WR',
    ]);
    $oldOnlyWr = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'WR',
        'full_name' => 'Old Only WR',
    ]);

    foreach ([2023, 2024] as $season) {
        $game = Game::factory()->create([
            'season' => $season,
            'season_type' => config('nfl.season.types.regular'),
            'game_date' => "{$season}-10-01 12:00:00",
            'status' => config('nfl.statuses.final'),
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
            'home_score' => 24,
            'away_score' => 17,
        ]);

        PlayerStat::query()->create([
            'player_id' => $qb->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'passing_attempts' => 35,
        ]);
        PlayerStat::query()->create([
            'player_id' => $rb->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'rushing_attempts' => 18,
            'receiving_targets' => 4,
        ]);
        PlayerStat::query()->create([
            'player_id' => $wr->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'receiving_targets' => 9,
        ]);
    }

    $oldGame = Game::factory()->create([
        'season' => 2023,
        'season_type' => config('nfl.season.types.regular'),
        'game_date' => '2023-10-08 12:00:00',
        'status' => config('nfl.statuses.final'),
        'home_team_id' => $team->id,
        'away_team_id' => $opponent->id,
        'home_score' => 27,
        'away_score' => 14,
    ]);

    PlayerStat::query()->create([
        'player_id' => $oldOnlyWr->id,
        'game_id' => $oldGame->id,
        'team_id' => $team->id,
        'receiving_targets' => 12,
    ]);

    PlayerInjury::query()->create([
        'player_id' => $qb->id,
        'team_id' => $team->id,
        'injury_key' => 'stable-qb-out',
        'status' => 'Out',
        'injury_date' => '2025-07-25',
        'source_updated_at' => '2025-07-31 12:00:00',
        'is_active' => true,
    ]);

    $signals = app(TeamOffseasonSignalService::class)->signalsForSeason(2025, '2025-08-01T12:00:00Z');

    expect($signals)->toHaveKey($team->id)
        ->and($signals[$team->id]['qb_continuity_signal'])->toBeGreaterThan(0.9)
        ->and($signals[$team->id]['skill_continuity_signal'])->toBeGreaterThan(0.0)
        ->and($signals[$team->id]['returning_production_share'])->toBeGreaterThan(0.6)
        ->and($signals[$team->id]['injury_adjustment'])->toBeLessThan(0.0)
        ->and($signals[$team->id]['offseason_adjustment'])->toBeGreaterThan(1.5);
});
