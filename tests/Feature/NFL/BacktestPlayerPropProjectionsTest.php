<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\BettingRecommendations\NflPropProjectionBacktest;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-10-22 12:00:00');
    $this->team = Team::factory()->create();
    $this->opponent = Team::factory()->create();
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'position' => 'RB']);
    $this->history = function ($date, $attempts, $overrides = []) {
        $game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
            'season' => 2026, 'season_type' => 2, 'game_date' => $date, 'game_time' => '17:00:00', 'status' => 'STATUS_FINAL', ...$overrides]);
        PlayerStat::factory()->create(['player_id' => $this->player->id, 'team_id' => $this->team->id, 'game_id' => $game->id, 'rushing_attempts' => $attempts]);

        return $game;
    };
    foreach (['2026-09-13', '2026-09-20', '2026-09-27'] as $date) {
        ($this->history)($date, 15);
    }
    $this->target = ($this->history)('2026-10-04', 10);
    $this->prop = PlayerProp::create(['game_id' => $this->target->id, 'player_id' => $this->player->id, 'player_name' => $this->player->full_name,
        'market' => 'player_rush_attempts', 'line' => 12.5, 'over_price' => 100, 'under_price' => -120, 'bookmaker' => 'draftkings', 'fetched_at' => '2026-10-04 10:00:00']);
    $this->runBacktest = fn () => app(NflPropProjectionBacktest::class)->run(2026, '2026-10-04');
});

it('compares the same chronological games without using future outcomes or rewriting forecasts', function () {
    $attributes = $this->prop->fresh()->getAttributes();
    $before = ($this->runBacktest)();
    ($this->history)('2026-10-11', 1000);
    ($this->history)('2026-09-30', 500, ['season_type' => 1]);
    $after = ($this->runBacktest)();
    expect($before)->toBe($after)
        ->and($after['coverage']['evaluated'])->toBe(1)
        ->and($after['markets']['player_rush_attempts']['old_mae'])->toBe(5.0)
        ->and($after['markets']['player_rush_attempts']['new_mae'])->toBe(5.0)
        ->and($after['markets']['player_rush_attempts']['new_bets']['losses'])->toBe(1)
        ->and($this->prop->fresh()->getAttributes())->toBe($attributes);
});

it('does not count multiple books as independent forecast observations and excludes postgame quotes', function () {
    $copy = $this->prop->replicate();
    $copy->bookmaker = 'otherbook';
    $copy->fetched_at = '2026-10-04 19:00:00';
    $copy->save();
    $report = ($this->runBacktest)();
    expect($report['coverage']['quotes'])->toBe(2)
        ->and($report['coverage']['evaluated'])->toBe(1)
        ->and($report['markets']['player_rush_attempts']['new_bets']['bets'])->toBe(1);
    $this->prop->update(['fetched_at' => '2026-10-01 15:00:00']);
    $report = ($this->runBacktest)();
    expect($report['coverage']['evaluated'])->toBe(1)
        ->and($report['coverage']['unusable_quotes'])->toBe(1)
        ->and($report['markets']['player_rush_attempts']['new_bets']['bets'])->toBe(0);
});

it('grades pushes and signed prices correctly without claiming production recommendation returns', function () {
    $this->prop->update(['line' => 10]);
    $report = ($this->runBacktest)();
    expect($report['markets']['player_rush_attempts']['new_bets']['pushes'])->toBe(1)
        ->and($report['markets']['player_rush_attempts']['new_bets']['units'])->toBe(0.0);
    $this->prop->update(['line' => 9.5, 'over_price' => -200]);
    $report = ($this->runBacktest)();
    expect($report['markets']['player_rush_attempts']['new_bets']['units'])->toBe(0.5)
        ->and($report['comparison'])->toBe('controlled_projection_baselines_only');
});

it('does not turn missing targets or missing historical stats into zero outcomes', function () {
    PlayerStat::where('game_id', $this->target->id)->update(['rushing_attempts' => null]);
    $report = ($this->runBacktest)();
    expect($report['coverage']['missing_actual'])->toBe(1)->and($report['coverage']['evaluated'])->toBe(0);
});

it('supports a read-only aggregate command and validates its scope', function () {
    $this->artisan('nfl:backtest-player-prop-projections', ['--season' => 2026, '--through' => '2026-10-04', '--json' => true])
        ->expectsOutputToContain('controlled_projection_baselines_only')->assertSuccessful();
    $this->artisan('nfl:backtest-player-prop-projections', ['--season' => 2026, '--through' => '2026-02-31'])->assertFailed();
    $this->artisan('nfl:backtest-player-prop-projections', ['--season' => 2026, '--through' => '2026-12-01'])->assertFailed();
    DB::enableQueryLog();
    DB::flushQueryLog();
    ($this->runBacktest)();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(collect($queries)->every(fn ($query) => str_starts_with(strtolower(ltrim($query['query'])), 'select')))->toBeTrue();
});

it('requires enough eligible starts after removing quarterback relief history', function () {
    $this->player->update(['position' => 'QB']);
    Game::where('home_team_id', $this->team->id)->update(['home_qb_name' => $this->player->full_name]);
    Game::whereDate('game_date', '2026-09-13')->update(['home_qb_name' => 'Other Starter']);
    $report = ($this->runBacktest)();
    expect($report['coverage']['insufficient_history'])->toBe(1)
        ->and($report['coverage']['evaluated'])->toBe(0);
});
