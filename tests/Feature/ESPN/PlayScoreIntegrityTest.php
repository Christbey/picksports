<?php

use App\Actions\ESPN\NBA\SyncPlays;
use App\Console\Commands\CFB\TrainEpaBaselineCommand;
use App\Models\NBA\Game;
use App\Models\NBA\Play;
use App\Models\NBA\Team;
use App\Services\Epa\StateBaselineService;
use App\Services\ESPN\NBA\EspnService;
use App\Services\NBA\PlayEpaDataService;
use App\Services\NBA\TrueEpaCalculator;
use App\Support\CFB\ObservedTerminalBoundary;

it('preserves every prior NBA play byte when a later provider score is malformed', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $old = Play::factory()->create(['game_id' => $game->id, 'true_epa' => '1.125']);
    $before = $old->fresh()->getRawOriginal();
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with($game->espn_event_id)->andReturn(['plays' => [
        ['id' => 'valid-new', 'homeScore' => 0, 'awayScore' => 0],
        ['id' => 'invalid-new', 'homeScore' => '2.5', 'awayScore' => 0],
    ]]);
    expect(fn () => (new SyncPlays($service))->execute($game->espn_event_id))->toThrow(InvalidArgumentException::class);
    expect($old->fresh()->getRawOriginal())->toBe($before)->and(Play::where('game_id', $game->id)->count())->toBe(1);
});

it('stores actual nullable play scores separately from observed zero through NBA sync', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->andReturn(['plays' => [
        ['id' => 'unknown'], ['id' => 'observed', 'homeScore' => 0, 'awayScore' => 0],
    ]]);
    expect((new SyncPlays($service))->execute($game->espn_event_id))->toBe(2);
    $rows = Play::where('game_id', $game->id)->orderBy('sequence_number')->get();
    expect($rows[0]->getRawOriginal('home_score'))->toBeNull()->and($rows[0]->getRawOriginal('away_score'))->toBeNull()
        ->and($rows[1]->getRawOriginal('home_score'))->toBe(0)->and($rows[1]->getRawOriginal('away_score'))->toBe(0);
});

function scoreIntegrityNbaPlay(int $id, ?int $home, ?int $away, string $clock = '10:00'): object
{
    return (object) ['id' => $id, 'home_score' => $home, 'away_score' => $away,
        'possession_team_id' => 10, 'period' => 1, 'clock' => $clock, 'play_type' => 'jump shot', 'play_text' => 'Synthetic play'];
}

it('never computes NBA scoring delta from a missing adjacent snapshot', function (?int $priorHome, ?float $expected) {
    config(['epa.state_baseline.enabled' => true]);
    $baselines = Mockery::mock(StateBaselineService::class);
    $baselines->shouldReceive('getMap')->with('nba', 2026)->once()->andReturn(['H1|q3|0-5' => 1.0]);
    $calculator = new TrueEpaCalculator(new PlayEpaDataService, $baselines);
    $rows = collect([scoreIntegrityNbaPlay(1, $priorHome, 0), scoreIntegrityNbaPlay(2, 2, 0), scoreIntegrityNbaPlay(3, 4, 0)]);
    expect($calculator->calculateForGame($rows, 10, 20, 2026)[2]['epa'])->toBe($expected);
})->with(['missing' => [null, null], 'real zero' => [0, 2.0]]);

it('does not learn NBA future points across a missing score or invent zero when no future boundary exists', function () {
    config(['epa.state_baseline.enabled' => false]);
    $calculator = new TrueEpaCalculator(new PlayEpaDataService, new StateBaselineService);
    $rows = collect([scoreIntegrityNbaPlay(1, 0, 0, '19:30'), scoreIntegrityNbaPlay(2, null, 0, '15:00'), scoreIntegrityNbaPlay(3, 3, 0, '01:00')]);
    $results = $calculator->calculateForGame($rows, 10, 20);
    expect($results[1]['ep_before'])->toBeNull()->and($results[3]['ep_before'])->toBeNull()
        ->and($results[1]['epa'])->toBeNull()->and($results[3]['epa'])->toBeNull();
});

it('retains an explicitly observed zero baseline without replacing unknown initial scoring with zero', function () {
    config(['epa.state_baseline.enabled' => true]);
    $baselines = Mockery::mock(StateBaselineService::class);
    $baselines->shouldReceive('getMap')->andReturn(['H1|q3|0-5' => 0.0]);
    $calculator = new TrueEpaCalculator(new PlayEpaDataService, $baselines);
    $results = $calculator->calculateForGame(collect([scoreIntegrityNbaPlay(1, 0, 0), scoreIntegrityNbaPlay(2, 2, 0), scoreIntegrityNbaPlay(3, 4, 0)]), 10, 20, 2026);
    expect($results[1]['ep_before'])->toBe(0.0)->and($results[1]['epa'])->toBeNull()->and($results[2]['epa'])->toBe(2.0);
});

it('keeps CFB EPA unavailable when an adjacent score is missing while retaining observed zero arithmetic', function (?int $priorHome, ?float $expected) {
    config(['cfb.scoring.past_epa_baseline' => true]);
    $baselines = Mockery::mock(StateBaselineService::class);
    $baselines->shouldReceive('getMap')->with('cfb', 2026)->once()->andReturn(['1|7-10|71-80' => 1.0]);
    $calculator = new App\Services\CFB\TrueEpaCalculator(new App\Services\CFB\PlayEpaDataService, $baselines);
    $base = ['possession_team_id' => 10, 'period' => 1, 'clock' => '10:00', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 75, 'play_type' => 'rush', 'play_text' => 'Synthetic run', 'away_score' => 0];
    $rows = collect([(object) [...$base, 'id' => 1, 'home_score' => $priorHome], (object) [...$base, 'id' => 2, 'home_score' => 6, 'is_scoring_play' => true]]);
    expect($calculator->calculateForGame($rows, 10, 20, 2026)[2]['epa'])->toBe($expected);
})->with(['missing' => [null, null], 'real zero' => [0, 5.0]]);

it('never reassigns an NFL missing scoring event to the next observed event', function (?int $priorHome, ?float $expected) {
    config(['epa.state_baseline.enabled' => true]);
    $baselines = Mockery::mock(StateBaselineService::class);
    $baselines->shouldReceive('getMap')->with('nfl', 2026)->once()->andReturn(['1|7-10|61-70' => 1.0]);
    $calculator = new App\Services\NFL\TrueEpaCalculator(new App\Services\NFL\PlayEpaDataService, $baselines);
    $base = ['possession_team_id' => 10, 'period' => 1, 'clock' => '10:00', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 70, 'play_type' => 'rush', 'play_text' => 'Synthetic run', 'away_score' => 0];
    $rows = collect([(object) [...$base, 'id' => 1, 'home_score' => $priorHome, 'is_scoring_play' => true], (object) [...$base, 'id' => 2, 'home_score' => 7, 'is_scoring_play' => true], (object) [...$base, 'id' => 3, 'home_score' => 7, 'is_scoring_play' => false]]);
    expect($calculator->calculateForGame($rows, 10, 20, 2026)[2]['epa'])->toBe($expected);
})->with(['missing scoring snapshot' => [null, null], 'observed zero scoring snapshot' => [0, 7.0]]);

it('bounds NBA future-score inspection linearly for a long observed scoreless sequence', function () {
    config(['epa.state_baseline.enabled' => false]);
    $counter = (object) ['reads' => 0];
    $rows = collect(range(1, 200))->map(fn ($id) => new class($id, $counter)
    {
        private array $values;

        public function __construct(int $id, private object $counter)
        {
            $this->values = ['id' => $id, 'home_score' => 0, 'away_score' => 0, 'possession_team_id' => 10, 'period' => 1, 'clock' => '10:00', 'play_type' => 'jump shot', 'play_text' => 'Synthetic observed scoreless play'];
        }

        public function __isset(string $key): bool
        {
            return array_key_exists($key, $this->values);
        }

        public function __get(string $key): mixed
        {
            if (in_array($key, ['home_score', 'away_score'], true)) {
                $this->counter->reads++;
            }

            return $this->values[$key] ?? null;
        }
    });
    $results = (new TrueEpaCalculator(new PlayEpaDataService, new StateBaselineService))->calculateForGame($rows, 10, 20);
    expect(count($results))->toBe(200)->and($counter->reads)->toBeLessThanOrEqual(200 * 100);
});

it('excludes unknown CFB future labels without running training or writing artifacts', function () {
    $command = new TrainEpaBaselineCommand;
    $method = new ReflectionMethod($command, 'observedFutureValue');
    $row = fn ($home) => (object) ['home_score' => $home, 'away_score' => 0, 'period' => 1];
    expect($method->invoke($command, collect([$row(null), $row(6)]), 1, 10, 10, 20))->toBeNull()
        ->and($method->invoke($command, collect([$row(0), $row(null), $row(6)]), 1, 10, 10, 20))->toBeNull()
        ->and($method->invoke($command, collect([$row(0), $row(0)]), 1, 10, 10, 20))->toBeNull()
        ->and($method->invoke($command, collect([$row(0), $row(6)]), 1, 10, 10, 20))->toBe(6.0);
});

it('does not admit an out-of-matchup NFL actor as a measured zero state sample', function () {
    config(['epa.state_baseline.enabled' => false]);
    $base = ['period' => 1, 'clock' => '10:00', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 70, 'play_type' => 'rush', 'play_text' => 'Synthetic run', 'home_score' => 0, 'away_score' => 0, 'is_scoring_play' => false];
    $calculator = new App\Services\NFL\TrueEpaCalculator(new App\Services\NFL\PlayEpaDataService, new StateBaselineService);
    $method = new ReflectionMethod($calculator, 'buildRealizedFuturePoints');
    $rows = [(object) [...$base, 'id' => 1, 'possession_team_id' => 999]];
    expect($method->invoke($calculator, $rows, 10, 20, [])[1])->toBeNull();
});

it('admits a measured zero CFB future value only at an observed terminal boundary', function () {
    $command = new TrainEpaBaselineCommand;
    $method = new ReflectionMethod($command, 'observedFutureValue');
    $row = fn (array $changes = []) => (object) [...['home_score' => 0, 'away_score' => 0, 'period' => 2, 'clock' => '0:10', 'play_type' => 'Rush', 'possession_team_id' => 10], ...$changes];
    $before = $row();
    $current = $row();
    foreach ([
        ['period' => 2, 'play_type' => 'End of Half'], ['period' => 2, 'play_type' => 'Halftime', 'possession_team_id' => null],
        ['period' => 4, 'play_type' => 'End of Game'], ['period' => 2, 'play_type' => 'End Period', 'possession_team_id' => null],
        ['period' => 4, 'play_type' => 'End of Period'],
    ] as $boundary) {
        $period = $boundary['period'];
        $plays = collect([$row(['period' => $period]), $row(['period' => $period]), $row([...$boundary, 'clock' => '0:00'])]);
        expect($method->invoke($command, $plays, 1, 10, 10, 20))->toBe(0.0);
    }
    foreach ([
        [],
        [$row(['play_type' => 'End of Half', 'clock' => '0:01'])],
        [$row(['play_type' => 'End of Half', 'clock' => '0:00', 'home_score' => null])],
        [$row(['play_type' => 'End of Half', 'clock' => '0:00', 'possession_team_id' => 999])],
        [$row(['play_type' => 'End of Half', 'clock' => '0:00', 'period' => 1])],
        [$row(['play_type' => 'End of Game', 'clock' => '0:00'])],
        [$row(['home_score' => null]), $row(['play_type' => 'End of Half', 'clock' => '0:00'])],
        [$row(['clock' => '0:00'])],
    ] as $tail) {
        expect($method->invoke($command, collect([$before, $current, ...$tail]), 1, 10, 10, 20))->toBeNull();
    }
});

it('uses an explicit observed CFB half-ending row rather than a truncated zero-clock play as the after-state', function () {
    $baseline = Mockery::mock(StateBaselineService::class);
    $baseline->shouldReceive('getMap')->with('cfb', 2026)->andReturn(['1|7-10|71-80' => 2.0]);
    $calculator = new App\Services\CFB\TrueEpaCalculator(new App\Services\CFB\PlayEpaDataService, $baseline);
    $row = fn (int $id, array $changes = []) => (object) [...['id' => $id, 'period' => 2, 'clock' => '0:10', 'play_type' => 'Rush', 'play_text' => 'Observed rush', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 75, 'home_score' => 0, 'away_score' => 0, 'possession_team_id' => 10, 'is_scoring_play' => false], ...$changes];
    $terminal = $row(3, ['play_type' => 'End of Half', 'clock' => '0:00', 'down' => 0]);
    $known = $calculator->calculateForGame(collect([$row(1), $row(2), $terminal]), 10, 20, 2026);
    expect($known[2]['ep_after'])->toBe(0.0)->and($known[2]['epa'])->toBe(-2.0);
    $truncated = $calculator->calculateForGame(collect([$row(1), $row(2, ['clock' => '0:00'])]), 10, 20, 2026);
    expect($truncated[2]['ep_after'])->toBeNull()->and($truncated[2]['epa'])->toBeNull();
    foreach ([['home_score' => null], ['possession_team_id' => 999], ['clock' => '0:01'], ['home_score' => 7, 'away_score' => 7]] as $changes) {
        $invalid = $calculator->calculateForGame(collect([$row(1), $row(2), $row(3, ['play_type' => 'End of Half', 'clock' => '0:00', 'down' => 0, ...$changes])]), 10, 20, 2026);
        expect($invalid[2]['ep_after'])->toBeNull()->and($invalid[2]['epa'])->toBeNull();
    }
});

it('never treats an administrative terminal with carried field position as an eligible CFB state', function (string $type) {
    $play = (object) ['play_type' => $type, 'play_text' => $type, 'period' => 2, 'clock' => '0:00',
        'down' => 1, 'distance' => 10, 'yards_to_endzone' => 75, 'possession_team_id' => 10, 'home_score' => 0, 'away_score' => 0];
    expect((new App\Services\CFB\PlayEpaDataService)->isEpaEligiblePlay($play))->toBeFalse();
})->with(['End of Period', 'Halftime']);

it('refuses a neutral CFB terminal witness when the game matchup itself is unknown or corrupt', function () {
    $terminal = (object) ['play_type' => 'End of Half', 'period' => 2, 'clock' => '0:00', 'home_score' => 0, 'away_score' => 0, 'possession_team_id' => null];
    foreach ([[0, 20], [10, 0], [10, 10]] as [$home, $away]) {
        expect(ObservedTerminalBoundary::forHalf($terminal, 2, $home, $away))->toBeFalse();
    }
});
