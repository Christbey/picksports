<?php

use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\Team;
use App\Models\User;
use App\Support\CFB\CfbGameStart;
use App\Support\PredictionFieldAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('withholds only clock dependent comparisons for unknown kickoff and preserves true UTC midnight', function (?string $clock) {
    Http::preventStrayRequests();
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(Carbon::parse('2026-09-12T00:02:00Z'));
    Sanctum::actingAs(User::factory()->create());
    $access = Mockery::mock(PredictionFieldAccess::class);
    $access->shouldReceive('canViewField')->andReturn(true);
    app()->instance(PredictionFieldAccess::class, $access);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-09-12', 'game_time' => $clock, 'espn_event_id' => 'synthetic-clock',
        ...($clock === '00:00:00' ? CfbGameStart::attributes('2026-09-12T00:00:00Z', [], 'espn_scoreboard', 'synthetic-clock') : []), 'status' => 'STATUS_IN_PROGRESS']);
    expect($game->getRawOriginal('game_time'))->toBe($clock);
    $quote = ['updated_at' => '2026-09-12T00:01:00Z', 'fresh' => true, 'difference' => 0, 'lean' => 'Over', 'line' => 0];
    $snapshot = LivePredictionSnapshot::create(['game_id' => $game->id, 'source' => 'synthetic_capture', 'state_hash' => hash('sha256', (string) $clock),
        'pregame' => ['total' => 0], 'state' => ['home_score' => 0, 'away_score' => 0], 'projection' => ['total' => 0],
        'markets' => [['market' => 'totals', 'updated_at' => $quote['updated_at'], 'fresh' => true, 'difference' => 0, 'lean' => 'Over', 'outcomes' => [['name' => 'Over', 'point' => 0]]]],
        'props' => [['player_id' => 42, 'market' => 'player_rush_yds', 'actual' => 0, 'quotes' => [$quote]]], 'status' => 'live', 'observed_at' => now()]);
    $before = $snapshot->fresh()->getRawOriginal();
    $response = $this->getJson('/api/v2/sports/cfb/games/'.$game->id.'/live-betting')->assertOk();
    $fresh = $clock !== '';
    $response->assertJsonPath('data.markets.0.fresh', $fresh)->assertJsonPath('data.props.0.quotes.0.fresh', $fresh)
        ->assertJsonPath('data.markets.0.lean', $fresh ? 'Over' : null)
        ->assertJsonPath('data.props.0.quotes.0.lean', $fresh ? 'Over' : null)
        ->assertJsonPath('data.markets.0.difference', $fresh ? 0 : null)
        ->assertJsonPath('data.markets.0.lean_at_capture', 'Over')
        ->assertJsonPath('data.props.0.quotes.0.difference_at_capture', 0)
        ->assertJsonPath('data.pregame.total', 0)->assertJsonPath('data.projection.total', 0)
        ->assertJsonPath('data.state.home_score', 0)->assertJsonCount(1, 'history');
    expect($snapshot->fresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
})->with(['', '00:00:00']);
