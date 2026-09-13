<?php

use App\Actions\ESPN\Concerns\UpdatesGameFromSummary;
use App\Actions\ESPN\NFL\SyncGamesFromScoreboard;
use App\Events\GameFinalized;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;
use Illuminate\Support\Facades\Event;

it('preserves final scores when the scoreboard regresses to pregame', function () {
    Event::fake([GameFinalized::class]);
    $home = Team::factory()->create(['espn_id' => '1']);
    $away = Team::factory()->create(['espn_id' => '2']);
    $game = Game::factory()->create(['espn_event_id' => '401999999', 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_FINAL', 'home_score' => 33, 'away_score' => 27, 'period' => 4]);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getScoreboard')->once()->andReturn(['events' => [[
        'id' => '401999999', 'date' => '2026-09-13T17:00:00Z', 'name' => 'Away at Home', 'shortName' => 'AWY @ HME', 'season' => ['year' => 2026, 'type' => 2], 'week' => ['number' => 1],
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED'], 'period' => 0],
        'competitions' => [['competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => '1'], 'score' => '0'],
            ['homeAway' => 'away', 'team' => ['id' => '2'], 'score' => '0'],
        ]]],
    ]]]);
    $live = new class
    {
        public function execute(Game $game): void {}
    };
    (new SyncGamesFromScoreboard($service, $live))->execute('20260913');
    $game->refresh();
    expect($game->status)->toBe('STATUS_FINAL')->and($game->home_score)->toBe(33)->and($game->away_score)->toBe(27)->and($game->period)->toBe(4);
});

it('preserves live scores when a details response contains only schedule state', function () {
    Event::fake([GameFinalized::class]);
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'status' => 'STATUS_IN_PROGRESS', 'home_score' => 24, 'away_score' => 17, 'period' => 3]);
    $action = new class
    {
        use UpdatesGameFromSummary { updateGameFromSummary as public apply; }
    };
    $action->apply(['header' => ['competitions' => [['status' => ['type' => ['name' => 'STATUS_SCHEDULED']], 'competitors' => [['homeAway' => 'home', 'score' => 0], ['homeAway' => 'away', 'score' => 0]]]]]], $game);
    $game->refresh();
    expect($game->status)->toBe('STATUS_IN_PROGRESS')->and($game->home_score)->toBe(24)->and($game->away_score)->toBe(17)->and($game->period)->toBe(3);
});
