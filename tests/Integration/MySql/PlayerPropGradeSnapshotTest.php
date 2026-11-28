<?php

use App\Actions\GradePlayerProps;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Models\PlayerPropGradeRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.prop_correction_writer' => config('database.connections.mysql')]);
    Http::preventStrayRequests();
});

afterEach(function () {
    config(['database.default' => 'mysql']);
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('prop_correction_writer');
});

it('uses current locked statistics and receipt despite a caller old repeatable-read snapshot', function (bool $newReceipt) {
    $team = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => Team::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 90]);
    $player = Player::factory()->create(['team_id' => $team->id]);
    $stat = PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $player->id, 'team_id' => $team->id, 'points' => 20]);
    $prop = PlayerProp::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => 'Snapshot player',
        'market' => 'player_points', 'line' => 19.5, 'over_price' => -110, 'predicted_over_probability' => 75]);
    DB::beginTransaction();
    DB::table('nba_player_stats')->where('id', $stat->id)->first();
    DB::connection('prop_correction_writer')->table('nba_player_stats')->where('id', $stat->id)->update(['points' => 18]);
    $before = null;
    if ($newReceipt) {
        config(['database.default' => 'prop_correction_writer']);
        app(GradePlayerProps::class)->executeForGame('basketball_nba', $game->id);
        $before = PlayerPropGradeRevision::query()->sole()->getAttributes();
        config(['database.default' => 'mysql']);
    }
    app(GradePlayerProps::class)->executeForGame('basketball_nba', $game->id);
    DB::commit();
    expect((float) $prop->fresh()->actual_value)->toBe(18.0)
        ->and($prop->fresh()->hit_over)->toBeFalse()
        ->and(PlayerPropGradeRevision::count())->toBe(1);
    if ($before !== null) {
        expect(PlayerPropGradeRevision::query()->sole()->getAttributes())->toBe($before);
    }
    Http::assertNothingSent();
})->with([false, true]);
