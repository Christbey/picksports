<?php

use App\Actions\ESPN\NFL\SyncPlays;
use App\Jobs\ESPN\NFL\FetchGameDetails;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('revisits a partial final game despite existing stat and play rows', function () {
    Bus::fake();
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_FINAL', 'game_date' => now()->subDays(10), 'home_score' => 41, 'away_score' => 31,
    ]);
    PlayerStat::factory()->create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'player_id' => Player::factory()->create(['team_id' => $game->home_team_id])->id]);
    TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $game->home_team_id]);
    $play = Play::factory()->create(['game_id' => $game->id, 'period' => 2, 'home_score' => 17, 'away_score' => 10]);
    $this->artisan('espn:sync-nfl-game-details', ['--lookback-days' => 14])->assertSuccessful();
    Bus::assertDispatched(FetchGameDetails::class, fn ($job) => $job->eventId === $game->espn_event_id);

    Bus::fake();
    $play->update(['period' => 4, 'home_score' => 41, 'away_score' => 31]);
    $this->artisan('espn:sync-nfl-game-details', ['--lookback-days' => 14])->assertSuccessful();
    Bus::assertNotDispatched(FetchGameDetails::class);
});

it('preserves stored plays when ESPN returns a truncated final feed', function () {
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'status' => 'STATUS_FINAL', 'home_score' => 41, 'away_score' => 31,
    ]);
    $play = Play::factory()->create(['game_id' => $game->id, 'period' => 4, 'home_score' => 41, 'away_score' => 31]);
    Http::fake(['*' => Http::response(['items' => [['id' => 'partial', 'period' => ['number' => 2], 'homeScore' => 17, 'awayScore' => 10]]])]);
    expect(fn () => (new SyncPlays(new EspnService))->execute($game->espn_event_id))->toThrow(RuntimeException::class, 'final score');
    expect($play->fresh())->not->toBeNull()->and(Play::where('game_id', $game->id)->count())->toBe(1);
});
