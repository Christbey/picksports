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

it('isolates formula differences from quarterback role-history changes', function () {
    $this->player->update(['position' => 'QB']);
    ($this->history)('2026-09-01', 15);
    Game::query()->update(['home_qb_name' => $this->player->full_name]);
    $reliefGame = Game::whereDate('game_date', '2026-09-13')->firstOrFail();
    $reliefGame->update(['home_qb_name' => 'Other Starter']);
    PlayerStat::where('game_id', $reliefGame->id)->update(['rushing_attempts' => 100]);
    $report = ($this->runBacktest)();
    $market = $report['markets']['player_rush_attempts'];

    expect($report['history_comparison'])->toBe('same_role_eligible_history_for_both_models')
        ->and($market['role_history_changed'])->toBe(1)
        ->and($market['old_mae'])->toBe(5.0)
        ->and($market['new_mae'])->toBe(5.0)
        ->and($market['unfiltered_old_mae'])->toBeGreaterThan($market['old_mae'])
        ->and($market['complete_opportunity_samples'])->toBe(1)
        ->and($market['stat_fallback_samples'])->toBe(0);
});

it('separates development and validation targets while retaining only pregame training history', function () {
    $validationGame = ($this->history)('2026-10-11', 12);
    $validationProp = $this->prop->replicate();
    $validationProp->game_id = $validationGame->id;
    $validationProp->fetched_at = '2026-10-11 10:00:00';
    $validationProp->save();
    $service = app(NflPropProjectionBacktest::class);
    $before = $service->run(2026, '2026-10-11', validationFrom: '2026-10-11');
    ($this->history)('2026-10-18', 1000);
    $after = $service->run(2026, '2026-10-11', validationFrom: '2026-10-11');

    expect($after)->toBe($before)
        ->and($after['coverage']['evaluated'])->toBe(2)
        ->and($after['date_split']['development']['coverage']['evaluated'])->toBe(1)
        ->and($after['date_split']['validation']['coverage']['evaluated'])->toBe(1)
        ->and($after['date_split']['development']['markets']['player_rush_attempts']['old_mae'])->toBe(5.0);

    $pending = $service->run(2026, '2026-10-11', validationFrom: '2026-10-23');
    expect($pending['date_split']['validation']['coverage']['evaluated'])->toBe(0)
        ->and($pending['date_split']['validation']['markets'])->toBe([]);
});

it('validates the chronological split date before reading evidence', function () {
    $this->artisan('nfl:backtest-player-prop-projections', [
        '--season' => 2026, '--through' => '2026-10-04', '--validation-from' => '2026-02-31',
    ])->assertFailed();
    $this->artisan('nfl:backtest-player-prop-projections', [
        '--season' => 2026, '--through' => '2026-10-04', '--validation-from' => '2026-10-23', '--json' => true,
    ])->expectsOutputToContain('validation_from')->assertSuccessful();
});

it('reports the offline stabilized candidate on the same samples and makes missing opportunities explicit', function () {
    $report = ($this->runBacktest)();
    $market = $report['markets']['player_rush_attempts'];
    expect($market['stabilized_mae'])->toBe(5.0)
        ->and($market['stabilized_mae_improvement'])->toBe(0.0)
        ->and($market['stabilized_bets']['losses'])->toBe(1);

    $this->prop->update(['market' => 'player_receptions']);
    PlayerStat::query()->update(['receptions' => 4, 'receiving_targets' => null]);
    $report = ($this->runBacktest)();
    $market = $report['markets']['player_receptions'];
    expect($market['complete_opportunity_samples'])->toBe(0)
        ->and($market['stat_fallback_samples'])->toBe(1)
        ->and($market['stabilized_mae'])->toBe($market['new_mae']);
});

it('bounds market audits without changing their samples or results', function () {
    $other = $this->prop->replicate();
    $other->market = 'player_rush_yds';
    $other->save();
    $service = app(NflPropProjectionBacktest::class);
    $all = $service->run(2026, '2026-10-04');
    $bounded = $service->run(2026, '2026-10-04', market: 'player_rush_attempts');

    expect($bounded['markets'])->toHaveKeys(['player_rush_attempts'])
        ->and($bounded['markets'])->toHaveCount(1)
        ->and($bounded['markets']['player_rush_attempts'])->toBe($all['markets']['player_rush_attempts'])
        ->and($bounded['coverage']['quotes'])->toBe(1);
    $this->artisan('nfl:backtest-player-prop-projections', [
        '--season' => 2026, '--through' => '2026-10-04', '--market' => 'player_rush_attempts', '--json' => true,
    ])->expectsOutputToContain('stabilized_mae')->assertSuccessful();
});

it('exports opt-in benchmark features with only eligible prior history and no player names', function () {
    $service = app(NflPropProjectionBacktest::class);
    $report = $service->run(2026, '2026-10-04', includeSamples: true);
    expect($report['sample_schema_version'])->toBe('nfl-prop-benchmark-v1')
        ->and($report['samples'])->toHaveCount(1);
    $sample = $report['samples'][0];
    expect($sample['actual'])->toBe(10.0)
        ->and($sample['feature_recent5_mean'])->toBe(15.0)
        ->and($sample['feature_history_games'])->toBe(3)
        ->and($sample['history_max_game_start_at'])->toBeLessThan($sample['game_start_at'])
        ->and($sample)->not->toHaveKey('player_name')
        ->and(($this->runBacktest)())->not->toHaveKey('samples');
    ($this->history)('2026-10-11', 1000);
    expect($service->run(2026, '2026-10-04', includeSamples: true)['samples'])->toBe($report['samples']);
    $this->artisan('nfl:backtest-player-prop-projections', ['--season' => 2026, '--include-samples' => true])->assertFailed();
});
