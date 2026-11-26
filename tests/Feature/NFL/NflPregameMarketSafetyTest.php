<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\Predictions\NflPregameMarketSnapshot;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use Illuminate\Support\Facades\DB;

function nflSafetyMarket(): array
{
    $kickoff = now()->addHours(5)->startOfSecond()->toImmutable();
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'game_date' => $kickoff->utc()->toDateString(), 'game_time' => $kickoff->utc()->toTimeString()]);
    $odds = ['home_team' => 'Home', 'away_team' => 'Away', 'bookmakers' => [[
        'key' => 'safe-book', 'markets' => [
            ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => -3, 'price' => -110], ['name' => 'Away', 'point' => 3, 'price' => -110]]],
            ['key' => 'totals', 'outcomes' => [['name' => 'Over', 'point' => 44, 'price' => -110], ['name' => 'Under', 'point' => 44, 'price' => -110]]],
        ],
    ]]];
    $snapshot = app(GameOddsSnapshotRecorder::class)->record('nfl', $game, ['commence_time' => $kickoff->toIso8601String()], $odds);

    return [$game, $kickoff, $snapshot];
}

it('accepts valid NFL paired quotes with genuinely absent provider timestamps', function () {
    [$game, $kickoff] = nflSafetyMarket();
    $market = app(NflPregameMarketSnapshot::class)->capture($game, now()->toImmutable(), $kickoff);
    expect($market['available'])->toBeTrue()->and($market['quotes'])->toHaveCount(4);
});

it('does not launder malformed provider timestamps through ingestion time', function (mixed $timestamp) {
    [$game, $kickoff, $snapshot] = nflSafetyMarket();
    DB::table('market_quotes')->where('game_odds_snapshot_id', $snapshot->id)
        ->update(['metadata' => json_encode(['provider_observed_at' => $timestamp])]);
    expect(app(NflPregameMarketSnapshot::class)->capture($game, now()->toImmutable(), $kickoff)['available'])->toBeFalse();
})->with(['unparseable' => ['not-a-date'], 'empty' => [''], 'wrong_type' => [42]]);

it('rejects NFL quotes whose evidence identity or timestamps do not match the capture', function (string $corruption) {
    [$game, $kickoff, $snapshot] = nflSafetyMarket();
    $captured = $corruption === 'observed_after_ingestion' ? now()->addMinute()->toImmutable() : now()->toImmutable();
    $change = match ($corruption) {
        'sport' => ['sport' => 'nba'],
        'game_table' => ['game_table' => 'nba_games'],
        'game_id' => ['game_id' => $game->id + 100],
        'kickoff' => ['commence_time' => $kickoff->addHour()],
        'missing_kickoff' => ['commence_time' => null],
        'capture' => ['captured_at' => $captured->subMinute()],
        'created_later' => ['created_at' => $captured->addMinute()],
        'observed_after_ingestion' => ['metadata' => json_encode(['provider_observed_at' => now()->addSecond()->toIso8601String()])],
    };
    DB::table('market_quotes')->where('game_odds_snapshot_id', $snapshot->id)->update($change);
    expect(app(NflPregameMarketSnapshot::class)->capture($game, $captured, $kickoff)['available'])->toBeFalse();
})->with(['sport', 'game_table', 'game_id', 'kickoff', 'missing_kickoff', 'capture', 'created_later', 'observed_after_ingestion']);

it('rejects NFL snapshots inserted after the capture or for a different kickoff', function (string $corruption) {
    [$game, $kickoff, $snapshot] = nflSafetyMarket();
    $captured = now()->toImmutable();
    DB::table('game_odds_snapshots')->where('id', $snapshot->id)->update($corruption === 'created_later'
        ? ['created_at' => $captured->addMinute()] : ['commence_time' => $kickoff->addHour()]);
    expect(app(NflPregameMarketSnapshot::class)->capture($game, $captured, $kickoff)['available'])->toBeFalse();
})->with(['created_later', 'kickoff']);
