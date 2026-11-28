<?php

use App\Actions\GradePlayerProps;
use App\Actions\OddsApi\NBA\SyncPlayerPropsForGames;
use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Models\PlayerPropGradeRevision;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\OddsApi\OddsApiService;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ObservedCfbGameFixture;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->team = Team::factory()->create();
    $this->game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => Team::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 90]);
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'first_name' => 'Receipt', 'last_name' => 'Player']);
    $this->stat = PlayerStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $this->team->id,
        'player_id' => $this->player->id, 'points' => 20, 'rebounds_total' => 5, 'assists' => 3]);
    $this->prop = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'player_name' => 'Receipt Player', 'market' => 'player_points', 'line' => 19.5, 'over_price' => -110,
        'under_price' => -105, 'predicted_over_probability' => 75]);
    $this->grader = app(GradePlayerProps::class);
});

it('corrects over under and push while retaining frozen entry evidence and append-only prior outcomes', function () {
    $entry = $this->prop->fresh()->only(PlayerProp::FROZEN_ENTRY_FIELDS);
    expect($this->grader->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(1);
    $this->stat->update(['points' => 18]);
    expect($this->grader->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(1)
        ->and($this->prop->fresh()->hit_over)->toBeFalse()
        ->and((float) $this->prop->fresh()->actual_value)->toBe(18.0)
        ->and($this->prop->fresh()->only(PlayerProp::FROZEN_ENTRY_FIELDS))->toBe($entry);
    $revisions = PlayerPropGradeRevision::orderBy('revision')->get();
    expect($revisions)->toHaveCount(2)
        ->and($revisions[1]->previous_projection['hit_over'])->toBeTrue()
        ->and($revisions[1]->projection['hit_over'])->toBeFalse()
        ->and($revisions[1]->entry_hash)->toBe($revisions[0]->entry_hash);
    expect($this->grader->executeForGame('basketball_nba', $this->game->id)['graded'])->toBe(0)
        ->and(PlayerPropGradeRevision::count())->toBe(2);
    Http::assertNothingSent();
});

it('moves a corrected grade to pending on missing stats and recovers a recorded zero without fabricating zero', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $this->stat->update(['points' => null]);
    $result = $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($result['pending_corrections'])->toBe(1)
        ->and($this->prop->fresh()->graded_at)->toBeNull()
        ->and($this->prop->fresh()->actual_value)->toBeNull()
        ->and($this->prop->fresh()->hit_over)->toBeNull()
        ->and(PlayerPropGradeRevision::orderByDesc('revision')->first()->previous_projection['actual_value'])->toBe(20);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect(PlayerPropGradeRevision::count())->toBe(2);
    $this->stat->update(['points' => 0]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect((float) $this->prop->fresh()->actual_value)->toBe(0.0)
        ->and($this->prop->fresh()->hit_over)->toBeFalse()->and(PlayerPropGradeRevision::count())->toBe(3);
});

it('uses semantic contents rather than replacement row identity or timestamps', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $attributes = $this->stat->getAttributes();
    unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
    $oldId = $this->stat->id;
    $this->stat->delete();
    $this->travel(2)->seconds();
    $new = PlayerStat::create($attributes);
    expect($new->id)->not->toBe($oldId);
    $result = $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($result['graded'])->toBe(0)->and(PlayerPropGradeRevision::count())->toBe(1);
});

it('fails closed on changed frozen entry even if a raw SQL writer bypasses model protection', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $before = $this->prop->fresh()->only(['actual_value', 'hit_over', 'error', 'graded_at']);
    DB::table('nba_player_props')->where('id', $this->prop->id)->update(['line' => 100]);
    $this->stat->update(['points' => 1]);
    expect($this->grader->executeForGame('basketball_nba', $this->game->id)['entry_conflicts'])->toBe(1)
        ->and($this->prop->fresh()->only(['actual_value', 'hit_over', 'error', 'graded_at']))->toEqual($before)
        ->and(PlayerPropGradeRevision::count())->toBe(1);
});

it('protects frozen entry and receipt immutability through quiet model paths', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $prop = $this->prop->fresh();
    $prop->line = 22;
    expect(fn () => $prop->saveQuietly())->toThrow(LogicException::class, 'frozen');
    $revision = PlayerPropGradeRevision::sole();
    $revision->entry_hash = str_repeat('a', 64);
    expect(fn () => $revision->saveQuietly())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $revision->deleteQuietly())->toThrow(LogicException::class, 'cannot be deleted');
});

it('rolls back projection changes when a receipt insert fails after SQL persistence', function () {
    $connection = DB::connection();
    $original = $connection->getEventDispatcher();
    $faults = clone $original;
    $faults->listen(QueryExecuted::class, function (QueryExecuted $query): void {
        if (preg_match('/\Ainsert into [`"]?player_prop_grade_revisions/i', $query->sql)) {
            throw new RuntimeException('Injected prop audit failure');
        }
    });
    $connection->setEventDispatcher($faults);
    try {
        expect(fn () => $this->grader->executeForGame('basketball_nba', $this->game->id))->toThrow(RuntimeException::class, 'audit failure');
    } finally {
        $connection->setEventDispatcher($original);
    }
    expect($this->prop->fresh()->graded_at)->toBeNull()->and(PlayerPropGradeRevision::count())->toBe(0);
});

it('bounds explicit repair and defaults to a genuinely read-only dry run', function () {
    $before = $this->prop->fresh()->getAttributes();
    $this->artisan('sports:reconcile-player-prop-grades', ['sport' => 'basketball_nba', '--ids' => (string) $this->prop->id])->assertSuccessful();
    expect($this->prop->fresh()->getAttributes())->toBe($before)->and(PlayerPropGradeRevision::count())->toBe(0);
    $this->artisan('sports:reconcile-player-prop-grades', ['sport' => 'basketball_nba', '--ids' => (string) $this->prop->id, '--apply' => true])->assertSuccessful();
    expect(PlayerPropGradeRevision::count())->toBe(1);
    $this->artisan('sports:reconcile-player-prop-grades', ['sport' => 'basketball_nba', '--ids' => implode(',', range(1, 101)), '--apply' => true])->assertFailed();
    $this->artisan('sports:reconcile-player-prop-grades', ['sport' => 'basketball_nba', '--ids' => (string) $this->prop->id, '--apply' => true, '--dry-run' => true])->assertFailed();
});

it('retains receipted props against raw deletion and game cascades through restrictive source foreign keys', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect(fn () => DB::table('nba_player_props')->where('id', $this->prop->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('nba_games')->where('id', $this->game->id)->delete())->toThrow(QueryException::class);
});

it('treats a newly ambiguous name and incomplete composite as pending instead of retaining a stale win', function () {
    $this->prop->update(['player_id' => null, 'player_name' => 'Receipt Player']);
    // Use the exact names actually returned by the normal matching rule.
    $this->player->update(['full_name' => 'Receipt Player']);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $other = Player::factory()->create(['team_id' => $this->team->id, 'full_name' => 'Receipt Player']);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $this->team->id, 'player_id' => $other->id, 'points' => 50]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($this->prop->fresh()->graded_at)->toBeNull()
        ->and(PlayerPropGradeRevision::orderByDesc('revision')->first()->state)->toBe('pending');
    $composite = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'player_name' => 'Receipt Player', 'market' => 'player_points_rebounds_assists', 'line' => 27]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $this->stat->update(['assists' => null]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($composite->fresh()->graded_at)->toBeNull()->and($composite->fresh()->actual_value)->toBeNull();
});

it('corrects into and out of a push without changing frozen probability or including the push in Brier samples', function () {
    $this->prop->update(['line' => 20]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($this->prop->fresh()->hit_over)->toBeNull()
        ->and($this->grader->calculateBrierScore('basketball_nba')['sample_size'])->toBe(0);
    $this->stat->update(['points' => 21]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($this->prop->fresh()->hit_over)->toBeTrue()
        ->and((float) $this->prop->fresh()->predicted_over_probability)->toBe(75.0)
        ->and($this->grader->calculateBrierScore('basketball_nba')['sample_size'])->toBe(1);
    $this->stat->update(['points' => 20]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($this->grader->calculateBrierScore('basketball_nba')['sample_size'])->toBe(0)
        ->and(PlayerPropGradeRevision::count())->toBe(3);
});

it('records an honest baseline for legacy grades without inventing the old source revision', function () {
    $this->prop->update(['actual_value' => 30, 'hit_over' => true, 'error' => 10.5, 'graded_at' => now()->subDay()]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $receipt = PlayerPropGradeRevision::sole();
    expect($receipt->reason)->toBe('legacy_grade_first_observation')
        ->and($receipt->previous_projection['actual_value'])->toBe(30)
        ->and($receipt->projection['actual_value'])->toBe(20)
        ->and($receipt->stat_snapshot['ordering'])->toBe('local_locked_observation_not_provider_revision');
});

it('rejects mismatched or multiple typed source identities even on quiet receipt creation', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $original = PlayerPropGradeRevision::sole()->getAttributes();
    unset($original['id']);
    foreach ([['sport' => 'baseball_mlb'], ['prop_table' => 'mlb_player_props'], ['prop_id' => 999], ['nfl_prop_id' => 1]] as $bad) {
        $receipt = new PlayerPropGradeRevision([...$original, 'revision' => 2, ...$bad]);
        expect(fn () => $receipt->saveQuietly())->toThrow(LogicException::class, 'exactly one');
    }
    expect(PlayerPropGradeRevision::count())->toBe(1);
});

it('retains receipted pending history while refreshing ordinary quotes into new rows', function () {
    $this->game->update(['odds_api_event_id' => 'receipt-event']);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $this->stat->update(['points' => null]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $frozen = $this->prop->fresh()->getAttributes();
    $ordinary = PlayerProp::create(['game_id' => $this->game->id, 'player_name' => 'Old mutable', 'market' => 'player_points', 'line' => 2]);
    $api = Mockery::mock(OddsApiService::class);
    $api->shouldReceive('getOdds')->once()->with('basketball_nba')->andReturn([[
        'id' => 'receipt-event', 'home_team' => 'Home', 'away_team' => 'Away', 'commence_time' => now()->toIso8601String(),
    ]]);
    $api->shouldReceive('getPlayerProps')->once()->andReturn(['bookmakers' => [[
        'key' => 'draftkings', 'markets' => [['key' => 'player_points', 'outcomes' => [
            ['name' => 'Over', 'description' => 'Receipt Player', 'point' => 22.5, 'price' => -115],
            ['name' => 'Under', 'description' => 'Receipt Player', 'point' => 22.5, 'price' => -105],
        ]]],
    ]]]);
    $api->shouldReceive('mappedEspnPlayerId')->andReturn(null);
    $api->shouldReceive('mappedEspnPlayerName')->andReturn(null);
    $sync = new SyncPlayerPropsForGames($api, app(SportsViewCache::class));
    expect($sync->execute(['player_points'], 'basketball_nba'))->toBe(1)
        ->and($this->prop->fresh()->getAttributes())->toBe($frozen)
        ->and($ordinary->fresh())->toBeNull()
        ->and(PlayerProp::query()->where('id', '!=', $this->prop->id)->sole()->line)->toBe('22.50')
        ->and(PlayerPropGradeRevision::count())->toBe(2);
});

it('refuses analyzer snapshot persistence for receipted pending entries through its normal writer methods', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $this->stat->update(['points' => null]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $before = $this->prop->fresh()->getAttributes();
    $analyzer = new class extends PlayerPropAnalyzer
    {
        public function attempt(Model $prop): void
        {
            $this->persistPredictionSnapshot($prop, ['recommendation' => 'Under', 'model_over_probability' => 1], 1, 1, 1);
            $this->clearPredictionSnapshot($prop);
        }
    };
    $analyzer->attempt($this->prop->fresh());
    expect($this->prop->fresh()->getAttributes())->toBe($before);
});

it('preserves a receipted CFB unique quote even after correction returns it to pending', function () {
    $this->travelTo('2026-09-12 12:00:00');
    $home = App\Models\CFB\Team::factory()->create();
    $game = App\Models\CFB\Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => App\Models\CFB\Team::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 14, 'game_date' => '2026-09-12', 'game_time' => '20:00:00', 'odds_api_event_id' => 'cfb-receipted']);
    $player = App\Models\CFB\Player::factory()->create(['espn_id' => 'receipt-quarterback-cfb', 'team_id' => $home->id, 'full_name' => 'Receipt Quarterback']);
    $stat = App\Models\CFB\PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $player->id,
        'team_id' => $home->id, 'passing_yards' => 300]);
    $key = hash('sha256', json_encode([$game->id, 'fanduel', 'player_pass_yds', 'Receipt Quarterback', '250.50']));
    $prop = App\Models\CFB\PlayerProp::create(['game_id' => $game->id, 'player_id' => $player->id, 'player_name' => 'Receipt Quarterback',
        'market' => 'player_pass_yds', 'quote_key' => $key, 'line' => 250.5, 'over_price' => -112, 'under_price' => -108,
        'bookmaker' => 'fanduel', 'predicted_over_probability' => 65]);
    // quote_key is force-filled by the real importer, not part of generic quote mass assignment.
    $prop->forceFill(['quote_key' => $key])->save();
    $this->grader->executeForGame('americanfootball_ncaaf', $game->id);
    $stat->update(['passing_yards' => null]);
    $this->grader->executeForGame('americanfootball_ncaaf', $game->id);
    $frozen = $prop->fresh()->only(PlayerProp::FROZEN_ENTRY_FIELDS);
    $game->update(['status' => 'STATUS_SCHEDULED']);
    // A new current verification after correction does not rewrite the retained quote/grade receipt.
    $game = ObservedCfbGameFixture::observe($game, '2026-09-12T20:00:00Z');
    $api = Mockery::mock(OddsApiService::class);
    $api->shouldReceive('getPlayerProps')->once()->andReturn(['id' => 'cfb-receipted', 'commence_time' => '2026-09-12T20:00:00Z',
        'bookmakers' => [['key' => 'fanduel', 'markets' => [['key' => 'player_pass_yds', 'outcomes' => [
            ['name' => 'Over', 'description' => 'Receipt Quarterback', 'point' => 250.5, 'price' => -150],
            ['name' => 'Under', 'description' => 'Receipt Quarterback', 'point' => 250.5, 'price' => -120],
        ]]]]]]);
    $result = (new App\Actions\OddsApi\CFB\SyncPlayerPropsForGames($api))->execute('2026-09-12', $game->id, false);
    expect($result['stored'])->toBe(0)
        ->and($prop->fresh()->only(PlayerProp::FROZEN_ENTRY_FIELDS))->toBe($frozen)
        ->and(App\Models\CFB\PlayerProp::where('game_id', $game->id)->count())->toBe(1);
});

it('blocks normal and quiet numeric mutation paths for append-only receipt and frozen entry evidence', function (string $method) {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $receipt = PlayerPropGradeRevision::sole();
    $before = $receipt->getAttributes();
    expect(fn () => $receipt->{$method}('revision'))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $this->prop->fresh()->{$method}('line'))->toThrow(LogicException::class, 'frozen')
        ->and($receipt->fresh()->getAttributes())->toBe($before)
        ->and($this->prop->fresh()->line)->toBe('19.50');
})->with(['increment', 'decrement', 'incrementQuietly', 'decrementQuietly']);

it('imports a partial-name quote without guessing a linked player and therefore keeps its grade pending', function () {
    $this->game->update(['odds_api_event_id' => 'identity-event']);
    $this->player->update(['full_name' => 'Receipt Player']);
    $api = Mockery::mock(OddsApiService::class);
    $api->shouldReceive('getOdds')->once()->andReturn([[
        'id' => 'identity-event', 'home_team' => 'Home', 'away_team' => 'Away', 'commence_time' => now()->toIso8601String(),
    ]]);
    $api->shouldReceive('getPlayerProps')->once()->andReturn(['bookmakers' => [[
        'key' => 'draftkings', 'markets' => [['key' => 'player_points', 'outcomes' => [
            ['name' => 'Over', 'description' => 'Receipt', 'point' => 19.5, 'price' => -110],
            ['name' => 'Under', 'description' => 'Receipt', 'point' => 19.5, 'price' => -105],
        ]]],
    ]]]);
    $api->shouldReceive('mappedEspnPlayerId')->andReturn(null);
    $api->shouldReceive('mappedEspnPlayerName')->andReturn(null);
    $api->shouldReceive('rememberUnmappedPlayer')->andReturnNull();
    (new SyncPlayerPropsForGames($api, app(SportsViewCache::class)))->execute(['player_points'], 'basketball_nba');
    $imported = PlayerProp::query()->sole();
    $result = $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($result['graded'])->toBe(0)->and($imported->player_id)->toBeNull()->and($imported->player_name)->toBe('Receipt')
        ->and($imported->fresh()->graded_at)->toBeNull();
});

it('refuses an invalid entire repair scope before changing an earlier valid prop', function () {
    $before = $this->prop->fresh()->getAttributes();
    $this->artisan('sports:reconcile-player-prop-grades', ['sport' => 'basketball_nba', '--ids' => $this->prop->id.',999999', '--apply' => true])->assertFailed();
    expect($this->prop->fresh()->getAttributes())->toBe($before)->and(PlayerPropGradeRevision::count())->toBe(0);
});

it('admits only a game-team explicit mapping or unique normalized exact full name at quote ingestion', function () {
    $wrongTeamPlayer = Player::factory()->create(['team_id' => Team::factory(), 'full_name' => 'Outside Player']);
    $this->player->update(['full_name' => 'Receipt Player']);
    $api = Mockery::mock(OddsApiService::class);
    $api->shouldReceive('mappedEspnPlayerId')->with('basketball_nba', 'Reviewed alias')->andReturn($this->player->id);
    $api->shouldReceive('mappedEspnPlayerId')->with('basketball_nba', 'Outside alias')->andReturn($wrongTeamPlayer->id);
    $api->shouldReceive('mappedEspnPlayerId')->with('basketball_nba', Mockery::not('Reviewed alias'))->andReturn(null);
    $api->shouldReceive('mappedEspnPlayerName')->with('basketball_nba', 'Mapped exact alias')->andReturn('RECEIPT-PLAYER');
    $api->shouldReceive('mappedEspnPlayerName')->with('basketball_nba', Mockery::not('Mapped exact alias'))->andReturn(null);
    $api->shouldReceive('rememberUnmappedPlayer')->andReturnNull();
    $sync = new class($api, app(SportsViewCache::class)) extends SyncPlayerPropsForGames
    {
        public function resolveForTest(string $name, Model $game): ?int
        {
            return $this->resolvePlayerId($name, $game, 'basketball_nba');
        }
    };
    expect($sync->resolveForTest('Reviewed alias', $this->game))->toBe($this->player->id)
        ->and($sync->resolveForTest('Mapped exact alias', $this->game))->toBe($this->player->id)
        ->and($sync->resolveForTest('RECEIPT-PLAYER', $this->game))->toBe($this->player->id)
        ->and($sync->resolveForTest('Outside alias', $this->game))->toBeNull()
        ->and($sync->resolveForTest('Player', $this->game))->toBeNull()
        ->and($sync->resolveForTest('Receip Player', $this->game))->toBeNull();
    Player::factory()->create(['team_id' => $this->team->id, 'full_name' => 'Receipt Player']);
    expect($sync->resolveForTest('Receipt Player', $this->game))->toBeNull();
});

it('refuses destructive migration rollback while retaining correction receipts', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $migration = require database_path('migrations/2026_11_25_050000_create_player_prop_grade_revisions.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Retain append-only')
        ->and(PlayerPropGradeRevision::count())->toBe(1);
});

it('repairs a missing graded timestamp from the known matching receipt without changing entry or prior audit', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $receipt = PlayerPropGradeRevision::sole();
    $original = $receipt->getAttributes();
    $knownTime = $receipt->projection['graded_at'];
    $this->prop->fresh()->update(['graded_at' => null]);
    $this->travel(5)->minutes();
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($this->prop->fresh()->graded_at?->toIso8601String())->toBe($knownTime)
        ->and($receipt->fresh()->getAttributes())->toBe($original)
        ->and(PlayerPropGradeRevision::count())->toBe(2)
        ->and(PlayerPropGradeRevision::orderByDesc('revision')->first()->previous_projection['graded_at'])->toBeNull();
});

it('clears a corrupted graded timestamp on an otherwise matching pending projection', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $this->stat->update(['points' => null]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $receipt = PlayerPropGradeRevision::orderByDesc('revision')->first();
    $original = $receipt->getAttributes();
    $this->prop->fresh()->update(['graded_at' => now()]);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($this->prop->fresh()->graded_at)->toBeNull()
        ->and($this->prop->fresh()->actual_value)->toBeNull()
        ->and($receipt->fresh()->getAttributes())->toBe($original)
        ->and(PlayerPropGradeRevision::count())->toBe(3)
        ->and(PlayerPropGradeRevision::orderByDesc('revision')->first()->previous_projection['graded_at'])->not->toBeNull();
});

it('labels a local timestamp repair when the matching receipt has no known grading timestamp', function () {
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    $receipt = PlayerPropGradeRevision::sole();
    $projection = $receipt->projection;
    $projection['graded_at'] = null;
    // Synthetic incomplete audit input; the supported receipt writer never emits this.
    DB::table('player_prop_grade_revisions')->where('id', $receipt->id)->update(['projection' => json_encode($projection)]);
    $this->prop->fresh()->update(['graded_at' => null]);
    $this->travel(7)->minutes();
    $observed = now()->startOfSecond()->toIso8601String();
    $before = $receipt->fresh()->getAttributes();
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect($this->prop->fresh()->graded_at?->toIso8601String())->toBe($observed)
        ->and($receipt->fresh()->getAttributes())->toBe($before)
        ->and(PlayerPropGradeRevision::orderByDesc('revision')->first()->reason)->toBe('timestamp_repaired_local_observation')
        ->and(PlayerPropGradeRevision::count())->toBe(2);
    $this->grader->executeForGame('basketball_nba', $this->game->id);
    expect(PlayerPropGradeRevision::count())->toBe(2);
});
