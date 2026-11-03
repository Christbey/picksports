<?php

use App\Events\GameFinalized;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MlbGameReconciliationRecord;
use App\Models\SportEvent;
use App\Services\ESPN\MLB\EspnService;
use App\Services\MLB\MlbStaleGameReconciler;
use Illuminate\Support\Facades\Event;

it('reconciles verified stale MLB finals atomically and preserves original state for audit', function (bool $apply) {
    Event::fake([GameFinalized::class]);
    $home = Team::factory()->create(['espn_id' => '1']);
    $away = Team::factory()->create(['espn_id' => '2']);
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'status' => 'STATUS_IN_PROGRESS']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'sport_event_id' => $event->id,
        'espn_event_id' => '123', 'game_date' => now()->subMonth(), 'status' => 'STATUS_IN_PROGRESS', 'home_score' => 0, 'away_score' => 1]);
    $this->mock(EspnService::class)->shouldReceive('getGame')->once()->with('123')->andReturn(['header' => ['id' => '123', 'competitions' => [[
        'id' => '123', 'status' => ['type' => ['name' => 'STATUS_FINAL', 'completed' => true], 'period' => 9],
        'competitors' => [['homeAway' => 'home', 'team' => ['id' => '1'], 'score' => '4'], ['homeAway' => 'away', 'team' => ['id' => '2'], 'score' => '3']],
    ]]]]);
    $result = app(MlbStaleGameReconciler::class)->reconcile($game, $apply);
    expect($result['outcome'])->toBe('reconciled')->and($game->fresh()->status)->toBe($apply ? 'STATUS_FINAL' : 'STATUS_IN_PROGRESS')
        ->and(MlbGameReconciliationRecord::count())->toBe($apply ? 1 : 0);
    if ($apply) {
        expect($game->fresh()->home_score)->toBe(4)->and($event->fresh()->status)->toBe('STATUS_FINAL')
            ->and(MlbGameReconciliationRecord::sole()->before['away_score'])->toBe(1);
        Event::assertDispatched(GameFinalized::class);
        expect(app(MlbStaleGameReconciler::class)->reconcile($game->fresh(), true)['outcome'])->toBe('skipped');
    } else {
        Event::assertNotDispatched(GameFinalized::class);
    }
})->with([false, true]);

it('holds unavailable or invalid provider evidence without inventing scores or finalization', function (string $variant) {
    Event::fake([GameFinalized::class]);
    $home = Team::factory()->create(['espn_id' => '1']);
    $away = Team::factory()->create(['espn_id' => '2']);
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'espn_event_id' => '123',
        'game_date' => now()->subMonth(), 'status' => 'STATUS_SCHEDULED', 'home_score' => null, 'away_score' => null]);
    $data = ['header' => ['id' => $variant === 'identity' ? '999' : '123', 'competitions' => [[
        'status' => ['type' => ['name' => 'STATUS_FINAL', 'completed' => true]],
        'competitors' => [['homeAway' => 'home', 'team' => ['id' => '1'], 'score' => null], ['homeAway' => 'away', 'team' => ['id' => '2'], 'score' => null]],
    ]]]];
    $this->mock(EspnService::class)->shouldReceive('getGame')->once()->andReturn($variant === 'missing' ? null : $data);
    $result = app(MlbStaleGameReconciler::class)->reconcile($game, true, true);
    expect($result['outcome'])->toBe('held')->and($game->fresh()->status)->toBe('STATUS_UNRESOLVED')
        ->and($game->fresh()->home_score)->toBeNull()->and(MlbGameReconciliationRecord::sole()->outcome)->toBe('held');
    Event::assertNotDispatched(GameFinalized::class);
})->with(['missing', 'identity', 'scores']);
