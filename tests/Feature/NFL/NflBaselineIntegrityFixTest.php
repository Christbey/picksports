<?php

use App\Actions\NFL\CalculateElo;
use App\Console\Commands\NFL\CalibrateSpreadCommand;
use App\Console\Commands\NFL\ImportNflverseSchedulesCommand;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('splits a tied Elo result and leaves equal neutral teams unchanged', function () {
    $home = Team::factory()->create(['elo_rating' => 1500]);
    $away = Team::factory()->create(['elo_rating' => 1500]);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-13', 'neutral_site' => true, 'home_score' => 20, 'away_score' => 20]);
    $result = app(CalculateElo::class)->execute($game);
    expect($result['home_change'])->toBe(0.0)->and($result['away_change'])->toBe(0.0)
        ->and((float) $home->fresh()->elo_rating)->toBe(1500.0)
        ->and((float) $away->fresh()->elo_rating)->toBe(1500.0)->and(EloRating::count())->toBe(2);
    expect(app(CalculateElo::class)->execute($game)['skipped'])->toBeTrue();
});

it('moves unequal tied teams toward each other rather than rewarding an away win', function () {
    $home = Team::factory()->create(['elo_rating' => 1600]);
    $away = Team::factory()->create(['elo_rating' => 1400]);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 5, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-10-11', 'neutral_site' => true, 'home_score' => 20, 'away_score' => 20]);
    $expected = round(16 * (0.5 - 1 / (1 + pow(10, -200 / 400))), 1);
    $result = app(CalculateElo::class)->execute($game);
    expect($result['home_change'])->toBe($expected)->and($result['away_change'])->toBe(-$expected);
});

it('does not turn missing final scores into a tie or write Elo history', function () {
    $home = Team::factory()->create(['elo_rating' => 1500]);
    $away = Team::factory()->create(['elo_rating' => 1500]);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'home_score' => null, 'away_score' => null]);
    expect(app(CalculateElo::class)->execute($game)['reason'])->toBe('missing_final_scores')
        ->and(EloRating::count())->toBe(0);
});

it('excludes same-day and future Elo from calibration and does not fabricate missing ratings', function () {
    $team = Team::factory()->create();
    foreach (['2026-09-12' => 1450, '2026-09-13' => 1800, '2026-09-14' => 2000] as $date => $rating) {
        EloRating::create(['team_id' => $team->id, 'season' => 2026, 'week' => 1,
            'date' => $date, 'elo_rating' => $rating, 'elo_change' => 0]);
    }
    $method = new ReflectionMethod(CalibrateSpreadCommand::class, 'getEloAtDate');
    expect($method->invoke(new CalibrateSpreadCommand, $team->id, '2026-09-13 23:59:59'))->toBe(1450.0)
        ->and($method->invoke(new CalibrateSpreadCommand, $team->id, '2026-09-12'))->toBeNull();
    $margin = new ReflectionMethod(CalibrateSpreadCommand::class, 'publishedMargin');
    expect($margin->invoke(new CalibrateSpreadCommand, 1000, 0.09))->toBe(15.0)
        ->and($margin->invoke(new CalibrateSpreadCommand, -1000, 0.09))->toBe(-15.0)
        ->and($margin->invoke(new CalibrateSpreadCommand, 26, 0.09))->toBe(2.3);
});

it('calibrates a frozen prior-date sample without repeated Elo queries or writes', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach ([$home, $away] as $team) {
        EloRating::create(['team_id' => $team->id, 'season' => 2026, 'week' => 0,
            'date' => '2026-09-01', 'elo_rating' => 1500, 'elo_change' => 0]);
    }
    Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-13', 'neutral_site' => false, 'home_score' => 23, 'away_score' => 20]);
    DB::enableQueryLog();
    try {
        expect(Artisan::call('nfl:calibrate-spread', ['--season' => 2026, '--min' => 0.02, '--max' => 0.1, '--step' => 0.005]))->toBe(0);
        expect(Artisan::output())->toContain('Eligible regular-season games: 1', 'not held-out ATS validation');
        $queries = collect(DB::getQueryLog())->pluck('query');
        expect($queries->filter(fn ($q) => str_contains($q, 'nfl_elo_ratings'))->count())->toBe(1)
            ->and($queries->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|alter|truncate)\b/i', $q))->count())->toBe(0);
    } finally {
        DB::disableQueryLog();
    }
});

it('rejects unsafe calibration grids', function ($args) {
    expect(Artisan::call('nfl:calibrate-spread', $args))->toBe(2);
})->with([
    [['--step' => 0]], [['--min' => 0.1, '--max' => 0.01]], [['--hfa' => 'nan']], [['--step' => 0.00000001]],
]);

it('converts Eastern kickoff in both daylight and standard time', function ($day, $utc) {
    config(['app.timezone' => 'America/Chicago']);
    $method = new ReflectionMethod(ImportNflverseSchedulesCommand::class, 'kickoff');
    $date = $method->invoke(new ImportNflverseSchedulesCommand, ['gameday' => $day, 'gametime' => '20:20']);
    expect($date->format('H:i'))->toBe('19:20')->and($date->utc()->format('Y-m-d H:i'))->toBe($utc);
    expect($method->invoke(new ImportNflverseSchedulesCommand, ['gameday' => $day]))->toBeNull();
})->with([['2026-09-13', '2026-09-14 00:20'], ['2026-12-13', '2026-12-14 01:20']]);

it('maps nflverse home margin into opposite sportsbook handicaps', function ($source) {
    $method = new ReflectionMethod(ImportNflverseSchedulesCommand::class, 'oddsPayload');
    $data = $method->invoke(new ImportNflverseSchedulesCommand, ['spread_line' => $source,
        'home_team' => 'KC', 'away_team' => 'DEN', 'home_spread_odds' => -115, 'away_spread_odds' => -105], null, null, null);
    expect(data_get($data, 'bookmakers.0.markets.0.outcomes.0.point'))->toBe(-$source)
        ->and(data_get($data, 'bookmakers.0.markets.0.outcomes.1.point'))->toBe($source)
        ->and(data_get($data, 'bookmakers.0.markets.0.outcomes.0.price'))->toBe(-115)
        ->and(data_get($data, 'bookmakers.0.markets.0.outcomes.1.price'))->toBe(-105);
})->with([6.5, -3.5, 0.0]);

it('fails calibration when only postgame ratings exist', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'week' => 1, 'season_type' => '2', 'status' => 'STATUS_FINAL',
        'game_date' => '2026-09-13', 'home_score' => 23, 'away_score' => 20]);
    foreach ([$home, $away] as $team) {
        EloRating::create(['team_id' => $team->id, 'game_id' => $game->id, 'season' => 2026, 'week' => 1,
            'date' => '2026-09-13', 'elo_rating' => 1500, 'elo_change' => 0]);
    }
    expect(Artisan::call('nfl:calibrate-spread', ['--season' => 2026]))->toBe(1)
        ->and(Artisan::output())->toContain('No games with both prior-date Elo ratings.');
});
