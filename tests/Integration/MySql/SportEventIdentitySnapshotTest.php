<?php

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Services\Sports\Exceptions\SportEventIdentityRetry;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.identity_writer' => config('database.connections.mysql')]);
    $this->game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'neutral_site' => false]);
    $this->event = app(SportEventIdentitySynchronizer::class)->sync('nba', $this->game);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('identity_writer');
});

it('refuses a changed identity target behind an inherited repeatable-read snapshot before mutation', function () {
    $replacement = SportEvent::factory()->create(['sport' => 'nba', 'neutral_site' => false]);
    DB::beginTransaction();
    Game::findOrFail($this->game->id); // Pin the old discovery snapshot without taking a lock.
    $writer = DB::connection('identity_writer');
    $writer->transaction(function () use ($writer, $replacement): void {
        $writer->table('nba_games')->where('id', $this->game->id)->update(['sport_event_id' => $replacement->id]);
        $writer->table('sport_event_provider_mappings')->where('sport_event_id', $this->event->id)->update(['sport_event_id' => $replacement->id]);
    });
    $called = false;
    expect(fn () => app(SportEventIdentitySynchronizer::class)->mutateAndSync('nba', $this->game, function (Game $locked) use (&$called): void {
        $called = true;
        $locked->update(['neutral_site' => true]);
    }))->toThrow(SportEventIdentityRetry::class);
    expect($called)->toBeFalse();
    DB::rollBack();
    expect($this->game->fresh()->sport_event_id)->toBe($replacement->id)
        ->and($this->game->fresh()->neutral_site)->toBeFalse()
        ->and($replacement->fresh()->neutral_site)->toBeFalse();
});

it('supports a stable inherited identity while pairing the current game and event update', function () {
    DB::beginTransaction();
    Game::findOrFail($this->game->id);
    app(SportEventIdentitySynchronizer::class)->mutateAndSync('nba', $this->game, function (Game $locked): void {
        $locked->update(['neutral_site' => true]);
    }, true);
    DB::commit();
    expect($this->game->fresh()->neutral_site)->toBeTrue()
        ->and($this->event->fresh()->neutral_site)->toBeTrue()
        ->and(SportEventProviderMapping::where('sport_event_id', $this->event->id)->count())->toBe(1);
});
