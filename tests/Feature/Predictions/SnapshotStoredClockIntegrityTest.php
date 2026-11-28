<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEvent;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Services\Sports\SportEventIdentityBackfill;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\Sports\GameDateTimePresenter;
use Tests\Support\ObservedCfbGameFixture;

it('uses the observed UTC clock rather than a local or invented clock', function (string $sport, ?string $clock, ?string $expected) {
    config(['app.timezone' => 'America/Chicago']);
    $class = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $game = new $class;
    $game->setRawAttributes(['game_date' => '2026-09-14', 'game_time' => $clock], true);
    if ($sport === 'cfb' && $clock !== null) {
        $this->travelTo('2026-09-14T17:00:00Z');
        try {
            $game = App\Models\CFB\Game::factory()->create([
                'home_team_id' => App\Models\CFB\Team::factory(),
                'away_team_id' => App\Models\CFB\Team::factory(), 'season' => 2026,
                'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-09-14', 'game_time' => $clock,
            ]);
            $game = ObservedCfbGameFixture::observe($game, '2026-09-14T18:00:00Z');
        } finally {
            $this->travelBack();
        }
    }
    $game->setRelation('sportEvent', null);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [], '2026-09-14T19:00:00Z');

    expect($result['game_start_at']?->utc()->toIso8601String())->toBe($expected)
        ->and($result['pregame_safe'])->toBeFalse()
        ->and($result['availability_status'])->toBe($expected === null ? 'game_start_unknown' : 'after_game_start');
})->with(function () {
    foreach (['nfl', 'cfb', 'nba', 'wnba', 'cbb', 'wcbb'] as $sport) {
        yield $sport.' observed' => [$sport, '18:00:00', '2026-09-14T18:00:00+00:00'];
        yield $sport.' unknown' => [$sport, null, null];
    }
});

it('cannot promote an unknown start with supplied safety flags', function () {
    $game = new Game;
    $game->setRawAttributes(['game_date' => '2026-09-14', 'game_time' => null], true);
    $game->setRelation('sportEvent', null);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [
        'pregame_safe' => true, 'point_in_time_verified' => true, 'availability_status' => 'observed_pregame',
    ], '2026-09-14T19:00:00Z');

    expect($result['pregame_safe'])->toBeFalse()
        ->and($result['availability_status'])->toBe('game_start_unknown')
        ->and($result['lineage_metadata']['point_in_time_verified'])->toBeFalse();
});

it('does not prefer a cached canonical midnight to a known missing NFL clock', function () {
    $game = new Game;
    $game->setRawAttributes(['game_date' => '2026-09-14', 'game_time' => '00:00:00', 'nflverse_game_id' => '2026_02_A_B'], true);
    $game->odds_data = ['event_id' => 'nflverse:2026_02_A_B', 'commence_time' => null, 'market_context' => [
        'source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2',
    ]];
    $game->setRelation('sportEvent', new SportEvent(['starts_at' => '2026-09-14T00:00:00Z']));
    expect(app(SnapshotProvenanceResolver::class)->gameStartAt($game))->toBeNull();
});

it('retains genuine midnight and pregame UTC observations', function (string $clock, string $capture) {
    $game = new Game;
    $game->setRawAttributes(['game_date' => '2026-09-14', 'game_time' => $clock], true);
    $game->setRelation('sportEvent', null);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [], $capture);
    expect($result['pregame_safe'])->toBeTrue()
        ->and($result['availability_status'])->toBe('observed_pregame');
})->with([
    ['00:00:00', '2026-09-13T23:59:59Z'],
    ['18:00:00', '2026-09-14T17:59:59Z'],
]);

it('cannot promote an ordinary postgame capture with supplied safety flags', function () {
    $game = new Game;
    $game->setRawAttributes(['game_date' => '2026-09-14', 'game_time' => '18:00:00'], true);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [
        'pregame_safe' => true, 'point_in_time_verified' => true, 'availability_status' => 'observed_pregame',
    ], '2026-09-14T19:00:00Z');
    expect($result['pregame_safe'])->toBeFalse()
        ->and($result['availability_status'])->toBe('after_game_start')
        ->and($result['lineage_metadata']['point_in_time_verified'])->toBeFalse();
});

it('does not write a fabricated canonical kickoff from known-missing NFL clocks', function (bool $backfill) {
    config(['app.timezone' => 'America/Chicago']);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory(),
        'away_team_id' => Team::factory(),
        'game_date' => '2026-09-14', 'game_time' => '00:00:00', 'nflverse_game_id' => '2026_02_A_B',
        'odds_data' => ['event_id' => 'nflverse:2026_02_A_B', 'commence_time' => null, 'market_context' => [
            'source' => 'nflverse_schedules', 'normalization_version' => 'nflverse_schedule_v2',
        ]],
    ]);
    if ($backfill) {
        app(SportEventIdentityBackfill::class)->backfill(['nfl']);
        $event = $game->fresh()->sportEvent;
    } else {
        $event = app(SportEventIdentitySynchronizer::class)->sync('nfl', $game);
    }
    expect($event)->not->toBeNull()->and($event->starts_at)->toBeNull();
})->with([false, true]);

it('preserves supported fractional stored clocks', function (string $clock) {
    config(['trends.timezones.nfl.display' => 'UTC']);
    $game = new Game;
    $game->setRawAttributes(['game_date' => '2026-09-14', 'game_time' => $clock], true);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, [], '2026-09-14T17:00:00Z');
    expect($result['game_start_at']?->format('Y-m-d\TH:i:s.uP'))->toBe('2026-09-14T'.$clock.'+00:00')
        ->and($result['pregame_safe'])->toBeTrue()
        ->and(GameDateTimePresenter::forGame('nfl', $game)['game_time'])->toBe('18:00:00');
})->with(['18:00:00.000000', '18:00:00.123456']);
