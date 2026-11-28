<?php

use App\Actions\WNBA\CalculateElo;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.wnba_elo_writer' => config('database.connections.mysql')]);
    $this->home = Team::factory()->create(['elo_rating' => 1500]);
    $this->away = Team::factory()->create(['elo_rating' => 1500]);
    $this->game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'season' => 2025, 'week' => 1, 'status' => 'STATUS_FINAL', 'home_score' => 90, 'away_score' => 80]);
});

afterEach(function () {
    foreach ([DB::connection(), DB::connection('wnba_elo_writer')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('wnba_elo_writer');
});

it('computes from current game and team facts after an older repeatable-read snapshot', function () {
    $calculator = new CalculateElo;
    $writer = DB::connection('wnba_elo_writer');
    DB::beginTransaction();
    DB::table('wnba_teams')->get();
    $writer->table('wnba_teams')->where('id', $this->home->id)->update(['elo_rating' => 1600]);
    $writer->table('wnba_games')->where('id', $this->game->id)->update(['home_score' => 80, 'away_score' => 90]);
    $expected = $calculator->previewChanges(Game::on('wnba_elo_writer')->findOrFail($this->game->id), 1600, 1500);
    $result = $calculator->execute($this->game);
    DB::commit();
    expect($result['home_change'])->toBe($expected['home_change'])
        ->and($result['away_change'])->toBe($expected['away_change'])
        ->and((float) $this->home->fresh()->elo_rating)->toBe((float) $expected['home_new_elo'])
        ->and(EloRating::query()->where('game_id', $this->game->id)->count())->toBe(2);
});

it('does not reapply history published by a named writer behind an old snapshot', function () {
    DB::beginTransaction();
    DB::table('wnba_elo_ratings')->get();
    $calculator = new CalculateElo;
    $calculator->execute(Game::on('wnba_elo_writer')->findOrFail($this->game->id));
    $writer = DB::connection('wnba_elo_writer');
    $before = $writer->table('wnba_elo_ratings')->orderBy('id')->get()->toJson();
    $result = $calculator->execute($this->game);
    DB::commit();
    expect($result['skipped'])->toBeTrue()
        ->and($writer->table('wnba_elo_ratings')->orderBy('id')->get()->toJson())->toBe($before);
});
