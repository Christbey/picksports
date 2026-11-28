<?php

use App\Actions\ESPN\NBA\SyncGames;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\SportEvent;
use App\Services\ESPN\BaseEspnService;
use App\Services\GameFinalizationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('preserves stored game and canonical identity when an upstream score is malformed', function (mixed $score) {
    Http::preventStrayRequests();
    $home = Team::factory()->create(['espn_id' => '10']);
    $away = Team::factory()->create(['espn_id' => '20']);
    $game = Game::factory()->create(['espn_event_id' => 'score-observation-fixture', 'season' => 2026,
        'season_type' => 2, 'week' => 1, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'game_date' => '2026-09-01', 'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED',
        'home_score' => 23, 'away_score' => 20]);
    $before = (array) DB::table('nba_games')->find($game->id);
    $events = SportEvent::query()->count();
    $payload = ['id' => 'score-observation-fixture', 'date' => '2026-09-01T18:00:00Z',
        'season' => ['year' => 2026, 'type' => 2], 'week' => ['number' => 1],
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        'competitions' => [['neutralSite' => false, 'competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => '10'], 'score' => $score],
            ['homeAway' => 'away', 'team' => ['id' => '20'], 'score' => '20'],
        ]]]];
    $service = Mockery::mock(BaseEspnService::class);
    $service->shouldReceive('getGames')->once()->andReturn(['items' => [$payload]]);
    $dispatcher = Mockery::mock(GameFinalizationDispatcher::class);
    $dispatcher->shouldReceive('dispatchIfFinalizedTransition')->andReturnNull();
    app()->instance(GameFinalizationDispatcher::class, $dispatcher);
    $refused = false;
    try {
        (new SyncGames($service))->execute(2026, 2, 1);
    } catch (InvalidArgumentException) {
        $refused = true;
    }
    expect((array) DB::table('nba_games')->find($game->id))->toBe($before)
        ->and(SportEvent::query()->count())->toBe($events)
        ->and($refused)->toBeTrue();
})->with(['overflow string' => '1e999', 'fractional score' => '21.5']);
