<?php

use App\AI\Agents\PlayerPropNarrativeAgent;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Player as MlbPlayer;
use App\Models\MLB\PlayerProp as MlbPlayerProp;
use App\Models\MLB\PlayerStat as MlbPlayerStat;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Models\OddsApiPlayerMapping;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;

test('analyzes props for completed games', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-25',
        'game_time' => '19:00:00',
        'season' => 2026,
    ]);

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'LeBron James',
        'first_name' => 'LeBron',
        'last_name' => 'James',
    ]);

    // Create enough game history for minimum games threshold
    for ($i = 0; $i < 5; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 1)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 28,
        ]);
    }

    PlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'LeBron James',
        'market' => 'player_points',
        'line' => 20.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $analyzer = new PlayerPropAnalyzer;
    $recommendations = $analyzer->analyzeProps('NBA', 3, '2026-02-25');

    expect($recommendations)->toHaveCount(1);
    expect($recommendations->first()['recommendation'])->toBe('Over');
    expect($recommendations->first()['market'])->toBe('Points');
});

test('analyzes mlb pitcher strikeout props', function () {
    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-07',
        'game_time' => '19:00:00',
        'season' => 2026,
    ]);

    $player = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Logan Webb',
        'first_name' => 'Logan',
        'last_name' => 'Webb',
    ]);

    for ($i = 0; $i < 5; $i++) {
        $historicalGame = MlbGame::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 1)->toDateString(),
            'season' => 2026,
        ]);

        MlbPlayerStat::factory()->pitching()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'strikeouts_pitched' => 8,
        ]);
    }

    MlbPlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Logan Webb',
        'market' => 'pitcher_strikeouts',
        'line' => 1.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $analyzer = new PlayerPropAnalyzer;
    $recommendations = $analyzer->analyzeProps('MLB', 3, '2026-06-07');
    $prop = MlbPlayerProp::query()->first();

    expect($prop?->recommended_side)->toBe('Over')
        ->and($prop?->confidence_score)->not->toBeNull()
        ->and($prop?->predicted_over_probability)->not->toBeNull()
        ->and($prop?->market_over_probability)->not->toBeNull()
        ->and($prop?->edge_probability)->not->toBeNull()
        ->and($prop?->data_quality_score)->not->toBeNull()
        ->and(data_get($prop?->confidence_decomposition, 'cover_record.season.recommendation_record'))->toBe('5-0')
        ->and(data_get($prop?->confidence_decomposition, 'cover_record.last_5.record'))->toBe('5-0')
        ->and(data_get($prop?->confidence_decomposition, 'stat_summary.season_avg'))->toEqual(8.0);
});

test('clears stale player prop recommendation when analysis can no longer recompute it', function () {
    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-07',
        'season' => 2026,
    ]);

    $prop = MlbPlayerProp::create([
        'game_id' => $game->id,
        'player_name' => 'Unknown Stale Player',
        'market' => 'batter_runs_scored',
        'line' => 0.5,
        'over_price' => 112,
        'under_price' => -142,
        'recommended_side' => 'Over',
        'confidence_score' => 98,
        'predicted_over_probability' => 67.6,
        'market_over_probability' => 43.7,
        'edge_probability' => 23.9,
        'data_quality_score' => 90,
        'match_quality_score' => 95,
    ]);

    $recommendations = (new PlayerPropAnalyzer)->analyzeProps('MLB', 3, '2026-06-07');
    $prop->refresh();

    expect($recommendations)->toHaveCount(0)
        ->and($prop->recommended_side)->toBeNull()
        ->and($prop->confidence_score)->toBeNull()
        ->and($prop->confidence_decomposition)->toBeNull();
});

test('precomputed player prop board ignores legacy rows without stat and cover records', function () {
    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-07',
        'season' => 2026,
    ]);

    $player = MlbPlayer::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Legacy Row',
        'first_name' => 'Legacy',
        'last_name' => 'Row',
    ]);

    MlbPlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Legacy Row',
        'market' => 'batter_runs_scored',
        'line' => 0.5,
        'over_price' => 112,
        'under_price' => -142,
        'recommended_side' => 'Over',
        'confidence_score' => 98,
        'predicted_over_probability' => 67.6,
        'market_over_probability' => 43.7,
        'edge_probability' => 23.9,
        'data_quality_score' => 90,
        'match_quality_score' => 95,
        'confidence_decomposition' => ['confidence_cap' => 96],
    ]);

    $recommendations = (new PlayerPropAnalyzer)->precomputedRecommendations('MLB', '2026-06-07');

    expect($recommendations)->toHaveCount(0);
});

test('caps volatile mlb player prop signal strength below elite confidence', function () {
    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-07',
        'game_time' => '19:00:00',
        'season' => 2026,
    ]);

    $player = MlbPlayer::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Power Bat',
        'first_name' => 'Power',
        'last_name' => 'Bat',
    ]);

    for ($i = 0; $i < 30; $i++) {
        $historicalGame = MlbGame::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 1)->toDateString(),
            'season' => 2026,
        ]);

        MlbPlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'home_runs' => 2,
        ]);
    }

    MlbPlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Power Bat',
        'market' => 'batter_home_runs',
        'line' => 0.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $recommendations = (new PlayerPropAnalyzer)->analyzeProps('MLB', 3, '2026-06-07');
    $prop = MlbPlayerProp::query()->first();

    expect($recommendations)->toHaveCount(1)
        ->and($prop?->recommended_side)->toBe('Over')
        ->and((int) $prop?->confidence_score)->toBeLessThanOrEqual(82)
        ->and(data_get($prop?->confidence_decomposition, 'confidence_cap'))->toBe(82);
});

test('cover record caps player prop signal when the season record does not support the recommendation', function () {
    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();

    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-07',
        'game_time' => '19:00:00',
        'season' => 2026,
    ]);

    $player = MlbPlayer::factory()->pitcher()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Recent Surge',
        'first_name' => 'Recent',
        'last_name' => 'Surge',
    ]);

    for ($i = 16; $i >= 0; $i--) {
        $historicalGame = MlbGame::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 1)->toDateString(),
            'season' => 2026,
        ]);

        MlbPlayerStat::factory()->pitching()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'strikeouts_pitched' => $i < 5 ? 10 : 4,
        ]);
    }

    MlbPlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Recent Surge',
        'market' => 'pitcher_strikeouts',
        'line' => 5.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $recommendations = (new PlayerPropAnalyzer)->analyzeProps('MLB', 3, '2026-06-07');
    $prop = MlbPlayerProp::query()->first();

    expect($recommendations)->toHaveCount(1)
        ->and($prop?->recommended_side)->toBe('Over')
        ->and((int) $prop?->confidence_score)->toBeLessThanOrEqual(68)
        ->and(data_get($prop?->confidence_decomposition, 'cover_record.season.recommendation_record'))->toBe('5-12')
        ->and(data_get($prop?->confidence_decomposition, 'cover_record_adjustment.applied_cap'))->toBe(68);
});

test('analyzes props regardless of game status', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Test Player',
        'first_name' => 'Test',
        'last_name' => 'Player',
    ]);

    // Create historical stats
    for ($i = 0; $i < 5; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 10)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 30,
        ]);
    }

    $statuses = ['STATUS_FINAL', 'STATUS_SCHEDULED', 'STATUS_IN_PROGRESS'];

    foreach ($statuses as $index => $status) {
        $game = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => $status,
            'game_date' => '2026-02-20',
            'season' => 2026,
        ]);

        PlayerProp::create([
            'game_id' => $game->id,
            'player_id' => $player->id,
            'player_name' => 'Test Player',
            'market' => 'player_points',
            'line' => 20.5,
            'over_price' => -110,
            'under_price' => -110,
        ]);
    }

    $analyzer = new PlayerPropAnalyzer;
    $recommendations = $analyzer->analyzeProps('NBA', 3, '2026-02-20');

    // All 3 props should produce recommendations regardless of game status
    expect($recommendations)->toHaveCount(3);
});

test('filters props by date', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Date Filter Player',
        'first_name' => 'Date',
        'last_name' => 'Filter Player',
    ]);

    // Create historical stats
    for ($i = 0; $i < 5; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 10)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 30,
        ]);
    }

    // Game on Feb 20
    $game1 = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-20',
        'season' => 2026,
    ]);

    // Game on Feb 21
    $game2 = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-21',
        'season' => 2026,
    ]);

    PlayerProp::create([
        'game_id' => $game1->id,
        'player_id' => $player->id,
        'player_name' => 'Date Filter Player',
        'market' => 'player_points',
        'line' => 20.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    PlayerProp::create([
        'game_id' => $game2->id,
        'player_id' => $player->id,
        'player_name' => 'Date Filter Player',
        'market' => 'player_points',
        'line' => 20.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $analyzer = new PlayerPropAnalyzer;

    // Filter to Feb 20 only
    $recommendations = $analyzer->analyzeProps('NBA', 3, '2026-02-20');
    expect($recommendations)->toHaveCount(1);

    // No date filter returns both
    $allRecs = $analyzer->analyzeProps('NBA', 3);
    expect($allRecs)->toHaveCount(2);
});

test('returns available dates for all games with props', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    // Past game with props
    $pastGame = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-20',
        'season' => 2026,
    ]);

    // Future game with props
    $futureGame = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-03-15',
        'season' => 2026,
    ]);

    foreach ([$pastGame, $futureGame] as $game) {
        PlayerProp::create([
            'game_id' => $game->id,
            'player_name' => 'Test Player',
            'market' => 'player_points',
            'line' => 25.5,
            'over_price' => -110,
            'under_price' => -110,
        ]);
    }

    $analyzer = new PlayerPropAnalyzer;
    $dates = $analyzer->getAvailableDatesForSport('NBA');

    // Both dates should appear
    expect($dates)->toHaveCount(2);
    expect($dates->pluck('value')->toArray())->toContain('2026-02-20', '2026-03-15');
});

test('returns available games for all statuses', function () {
    $homeTeam = Team::factory()->create(['abbreviation' => 'LAL', 'name' => 'Lakers']);
    $awayTeam = Team::factory()->create(['abbreviation' => 'BOS', 'name' => 'Celtics']);

    $finalGame = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_FINAL',
        'game_date' => '2026-02-25',
        'game_time' => '19:00:00',
        'season' => 2026,
    ]);

    $scheduledGame = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-02-25',
        'game_time' => '21:00:00',
        'season' => 2026,
    ]);

    foreach ([$finalGame, $scheduledGame] as $game) {
        PlayerProp::create([
            'game_id' => $game->id,
            'player_name' => 'Test Player',
            'market' => 'player_points',
            'line' => 25.5,
            'over_price' => -110,
            'under_price' => -110,
        ]);
    }

    $analyzer = new PlayerPropAnalyzer;
    $games = $analyzer->getAvailableGamesForSport('NBA', '2026-02-25');

    expect($games)->toHaveCount(2);
});

test('uses manual player mappings when fuzzy player name does not match', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Shai Gilgeous-Alexander',
        'first_name' => 'Shai',
        'last_name' => 'Gilgeous-Alexander',
    ]);

    for ($i = 0; $i < 6; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 3)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 29,
        ]);
    }

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-02-25',
        'season' => 2026,
    ]);

    OddsApiPlayerMapping::query()->create([
        'sport' => 'basketball_nba',
        'odds_api_player_name' => 'S. Gilgeous-Alexander',
        'espn_player_name' => 'Shai Gilgeous-Alexander',
    ]);

    PlayerProp::create([
        'game_id' => $game->id,
        'player_name' => 'S. Gilgeous-Alexander',
        'market' => 'player_points',
        'line' => 24.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $analyzer = new PlayerPropAnalyzer;
    $recommendations = $analyzer->analyzeProps('NBA', 3, '2026-02-25');

    expect($recommendations)->toHaveCount(1);
    expect($recommendations->first()['player']->id)->toBe($player->id);
});

test('persists suggested player mapping when fuzzy match is below auto-match threshold', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Shai Gilgeous Alexander',
        'first_name' => 'Shai',
        'last_name' => 'Gilgeous Alexander',
    ]);

    for ($i = 0; $i < 6; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 3)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 29,
        ]);
    }

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-02-25',
        'season' => 2026,
    ]);

    PlayerProp::create([
        'game_id' => $game->id,
        'player_name' => 'S Gilgeous-Alex',
        'market' => 'player_points',
        'line' => 24.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $analyzer = new PlayerPropAnalyzer;
    $analyzer->analyzeProps('NBA', 3, '2026-02-25');

    $mapping = OddsApiPlayerMapping::query()
        ->where('sport', 'basketball_nba')
        ->where('odds_api_player_name', 'S Gilgeous-Alex')
        ->first();

    expect($mapping)->not->toBeNull();
    expect($mapping->espn_player_name)->toBeNull();
    expect($mapping->suggested_espn_player_name)->toBe($player->full_name);
    expect($mapping->suggested_player_id)->toBe($player->id);
    expect($mapping->suggested_match_quality_score)->not->toBeNull();
});

test('filters recommendations by prop market', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Filter Market Player',
        'first_name' => 'Filter',
        'last_name' => 'Player',
    ]);

    for ($i = 0; $i < 6; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 3)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 28,
            'rebounds_total' => 9,
        ]);
    }

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-02-25',
        'season' => 2026,
    ]);

    PlayerProp::create([
        'game_id' => $game->id,
        'player_name' => 'Filter Market Player',
        'market' => 'player_points',
        'line' => 24.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    PlayerProp::create([
        'game_id' => $game->id,
        'player_name' => 'Filter Market Player',
        'market' => 'player_rebounds',
        'line' => 6.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $analyzer = new PlayerPropAnalyzer;
    $all = $analyzer->analyzeProps('NBA', 3, '2026-02-25');
    $pointsOnly = $analyzer->analyzeProps('NBA', 3, '2026-02-25', null, 'player_points');

    expect($all->count())->toBeGreaterThanOrEqual(2);
    expect($pointsOnly)->toHaveCount(1);
    expect($pointsOnly->first()['prop']->market)->toBe('player_points');
});

test('persists template player prop narrative when ai provider is template', function () {
    config()->set('ai.features.player_prop_narratives.provider', 'template');
    config()->set('services.openai.api_key', null);

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Narrative Template Player',
        'first_name' => 'Narrative',
        'last_name' => 'Template Player',
    ]);

    for ($i = 0; $i < 6; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 3)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 31,
        ]);
    }

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-02-25',
        'season' => 2026,
    ]);

    $prop = PlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Narrative Template Player',
        'market' => 'player_points',
        'line' => 24.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $recommendations = app(PlayerPropAnalyzer::class)->analyzeProps('NBA', 3, '2026-02-25');

    $prop->refresh();

    expect($recommendations)->toHaveCount(1)
        ->and($recommendations->first()['narrative'])->toBeArray()
        ->and($recommendations->first()['narrative']['generated_by'])->toBe('template-player-prop-v1')
        ->and($prop->narrative_json)->toBeArray()
        ->and($prop->narrative_input_hash)->not->toBeEmpty()
        ->and($prop->narrative_generated_at)->not->toBeNull();
});

test('uses ai structured agent for player prop narratives when openai provider is enabled', function () {
    config()->set('ai.features.player_prop_narratives.provider', 'openai');
    config()->set('ai.features.player_prop_narratives.model', 'gpt-4o-mini');
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');

    PlayerPropNarrativeAgent::fake([
        [
            'summary' => 'NBA prop lean: Over 24.5 Points for Narrative AI Player.',
            'key_points' => [
                'Model over probability leads the market.',
                'Recent form supports the over angle.',
                'Projection clears the line with room.',
            ],
            'risk_note' => 'Risk note: minutes volatility can still drag the over.',
            'betting_plan' => [
                'bet_pick' => 'Bet Over 24.5 Points.',
                'reasoning' => 'The model keeps the over probability above the market baseline.',
            ],
            'social_caption' => 'Narrative AI Player over 24.5 is the lean.',
        ],
    ])->preventStrayPrompts();

    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $homeTeam->id,
        'full_name' => 'Narrative AI Player',
        'first_name' => 'Narrative',
        'last_name' => 'AI Player',
    ]);

    for ($i = 0; $i < 6; $i++) {
        $historicalGame = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'status' => 'STATUS_FINAL',
            'game_date' => now()->subDays($i + 3)->toDateString(),
            'season' => 2026,
        ]);

        PlayerStat::factory()->create([
            'game_id' => $historicalGame->id,
            'player_id' => $player->id,
            'team_id' => $homeTeam->id,
            'points' => 32,
        ]);
    }

    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-02-25',
        'season' => 2026,
    ]);

    $prop = PlayerProp::create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'player_name' => 'Narrative AI Player',
        'market' => 'player_points',
        'line' => 24.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);

    $recommendations = app(PlayerPropAnalyzer::class)->analyzeProps('NBA', 3, '2026-02-25');

    $prop->refresh();

    expect($recommendations)->toHaveCount(1)
        ->and($recommendations->first()['narrative'])->toBeArray()
        ->and($recommendations->first()['narrative']['generated_by'])->toBe('openai:gpt-4o-mini')
        ->and($recommendations->first()['narrative']['summary'])->toContain('Over 24.5 Points')
        ->and($prop->narrative_provider)->toBe('openai')
        ->and($prop->narrative_model)->toBe('gpt-4o-mini');
});
