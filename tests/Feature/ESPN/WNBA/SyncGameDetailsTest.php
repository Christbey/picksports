<?php

use App\Actions\ESPN\WNBA\SyncGameDetails;
use App\Events\GameFinalized;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Services\ESPN\WNBA\EspnService;
use Illuminate\Support\Facades\Event;

function wnbaSummaryFixture(): array
{
    $home = Team::factory()->create(['espn_id' => '100']);
    $away = Team::factory()->create(['espn_id' => '200']);
    $game = Game::factory()->create(['espn_event_id' => '12345', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_END_PERIOD', 'home_score' => 50, 'away_score' => 40]);
    $data = ['header' => ['id' => '12345', 'competitions' => [[
        'status' => ['type' => ['name' => 'STATUS_FINAL', 'completed' => true], 'period' => 4, 'displayClock' => '0:00'],
        'competitors' => [['homeAway' => 'home', 'team' => ['id' => '100'], 'score' => '85'], ['homeAway' => 'away', 'team' => ['id' => '200'], 'score' => '75']],
    ]]]];

    return [$game, $data];
}

function wnbaSummaryAction(array $data, bool $imports): SyncGameDetails
{
    $provider = Mockery::mock(EspnService::class);
    $provider->shouldReceive('getGame')->once()->with('12345')->andReturn($data);
    $stats = [];
    foreach (range(1, 3) as $unused) {
        $mock = Mockery::mock();
        if ($imports) {
            $mock->shouldReceive('execute')->once()->andReturn(0);
        } else {
            $mock->shouldNotReceive('execute');
        }
        $stats[] = $mock;
    }

    return new SyncGameDetails($provider, ...$stats);
}

it('updates WNBA stale summaries and dispatches verified finalization once', function () {
    Event::fake([GameFinalized::class]);
    [$game, $data] = wnbaSummaryFixture();
    $result = wnbaSummaryAction($data, true)->execute('12345');
    expect($result['game_updated'])->toBeTrue()->and($game->fresh()->status)->toBe('STATUS_FINAL')
        ->and($game->fresh()->home_score)->toBe(85)->and($game->fresh()->away_score)->toBe(75)
        ->and($game->fresh()->sportEvent->status)->toBe('STATUS_FINAL');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
    wnbaSummaryAction($data, true)->execute('12345');
    Event::assertDispatchedTimes(GameFinalized::class, 1);
});

it('preserves WNBA holds when summary identity or final evidence is invalid', function (string $variant) {
    Event::fake([GameFinalized::class]);
    [$game, $data] = wnbaSummaryFixture();
    $before = $game->fresh()->getAttributes();
    match ($variant) {
        'event' => $data['header']['id'] = '999',
        'team' => $data['header']['competitions'][0]['competitors'][0]['team']['id'] = '999',
        'missing' => $data['header']['competitions'][0]['competitors'][0]['score'] = null,
        'negative' => $data['header']['competitions'][0]['competitors'][0]['score'] = -1,
        'fractional' => $data['header']['competitions'][0]['competitors'][0]['score'] = 85.5,
        'tie' => $data['header']['competitions'][0]['competitors'][0]['score'] = 75,
        'incomplete' => $data['header']['competitions'][0]['status']['type']['completed'] = false,
        'status' => $data['header']['competitions'][0]['status']['type']['name'] = null,
        'unknown' => $data['header']['competitions'][0]['status']['type']['name'] = 'STATUS_UNKNOWN',
    };
    expect(fn () => wnbaSummaryAction($data, false)->execute('12345'))->toThrow(RuntimeException::class)
        ->and($game->fresh()->getAttributes())->toBe($before);
    Event::assertNotDispatched(GameFinalized::class);
})->with(['event', 'team', 'missing', 'negative', 'fractional', 'tie', 'incomplete', 'status', 'unknown']);

it('does not replace WNBA final scores with a nonfinal summary', function () {
    [$game, $data] = wnbaSummaryFixture();
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 85, 'away_score' => 75]);
    $data['header']['competitions'][0]['status']['type'] = ['name' => 'STATUS_IN_PROGRESS', 'completed' => false];
    $result = wnbaSummaryAction($data, true)->execute('12345');
    expect($result['game_updated'])->toBeFalse()->and($game->fresh()->status)->toBe('STATUS_FINAL')
        ->and($game->fresh()->home_score)->toBe(85)->and($game->fresh()->away_score)->toBe(75);
});
