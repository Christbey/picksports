<?php

use App\Actions\ESPN\MLB\Concerns\ResolvesMlbGameDateParts;
use App\Actions\ESPN\MLB\SyncGameDetails;
use App\Actions\ESPN\MLB\SyncGames;
use App\Actions\ESPN\MLB\SyncGamesFromSchedule;
use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Actions\ESPN\MLB\SyncPlayerStats;
use App\Actions\ESPN\MLB\SyncPlays;
use App\Actions\ESPN\MLB\SyncTeamStats;
use App\Http\Resources\Api\V2\SportGameResource;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\ESPN\MLB\EspnService;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodModelInferenceService;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Services\Sports\SportsDateWindowService;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/ObservedMlbVenueFixture.php';

dataset('mlb synthetic observed venue starts', [
    'eastern' => ['Boston', 'MA'],
    'western' => ['Los Angeles', 'CA'],
    'central' => ['Chicago', 'IL'],
    'missing venue' => [null, null],
]);

beforeEach(function () {
    config(['app.timezone' => 'America/Chicago', 'sports.business_timezone' => 'America/Chicago']);
});

it('retains the explicit source instant through the MLB start helper', function (?string $city, ?string $state) {
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts($city, $state))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);

    expect(MlbGameStart::for($game)->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-02 06:30:00');
})->with('mlb synthetic observed venue starts');

it('retains the explicit source instant through canonical identity synchronization', function (?string $city, ?string $state) {
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts($city, $state))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    app(SportEventIdentitySynchronizer::class)->sync('mlb', $game);

    expect($game->fresh()->sportEvent->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-02 06:30:00');
})->with('mlb synthetic observed venue starts');

it('includes the observed instant in its correct Chicago date query', function (?string $city, ?string $state) {
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts($city, $state))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    $dates = app(SportsDateWindowService::class);
    $ids = $dates->applyGameDateWindow(Game::query(), $dates->forDate('2026-07-02'))->pluck('id')->all();

    expect($ids)->toContain($game->id);
})->with('mlb synthetic observed venue starts');

it('declines malformed supplied timestamps without normalizing them', function (mixed $value) {
    $resolver = new class
    {
        use ResolvesMlbGameDateParts;

        public function resolve(mixed $value): array
        {
            return $this->resolveMlbGameDateParts(['date' => $value]);
        }
    };
    expect(fn () => $resolver->resolve($value))->toThrow(InvalidArgumentException::class);
})->with(['2026-02-30T00:00:00Z', '2026-07-02', '2026-07-02T06:30:00', '2026-07-02T25:30:00Z', '2026-07-02T06:30:00+14:30', '', false, ['bad']]);

it('retains offset fold identity without claiming a lossy legacy canonical timestamp', function (string $source, string $expected) {
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts(null, null))->getAttributes();
    $attributes = array_replace($attributes, MlbGameStart::observed($source, ['date' => $source]));
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    expect(MlbGameStart::for($game)->utc()->format('Y-m-d H:i:s'))->toBe($expected);
    $canonical = $game->fresh()->sportEvent->starts_at;
    expect($canonical === null || $canonical->utc()->format('Y-m-d H:i:s') === $expected)->toBeTrue();
})->with([
    ['2026-11-01T01:30:00-05:00', '2026-11-01 06:30:00'],
    ['2026-11-01T01:30:00-06:00', '2026-11-01 07:30:00'],
    ['2026-07-02T15:30:00+09:00', '2026-07-02 06:30:00'],
    ['1965-07-02T06:30:00Z', '1965-07-02 06:30:00'],
]);

it('declines stale or partial provenance through the helper query resource and PIT reader', function (array $changes) {
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    if ($game->getConnection()->getDriverName() === 'mysql'
        && (in_array('not-a-timestamp', $changes, true) || in_array('not-a-time', $changes, true))) {
        $before = $game->fresh()->getAttributes();
        try {
            $game->newQuery()->whereKey($game->id)->update($changes);
            throw new RuntimeException('Native temporal storage unexpectedly accepted malformed corruption fixture.');
        } catch (QueryException $exception) {
            expect((int) $exception->errorInfo[1])->toBe(1292);
        }
        expect($game->fresh()->getAttributes())->toBe($before)
            ->and(MlbGameStart::for($game->fresh()))->not->toBeNull();

        return;
    }
    $game->newQuery()->whereKey($game->id)->update($changes); // SQLite affinity permits this trusted corruption fixture.
    $game = $game->fresh();
    $dates = app(SportsDateWindowService::class);
    expect(MlbGameStart::for($game))->toBeNull()
        ->and(MlbGameStart::before(Game::query(), Carbon::parse('2026-07-03', 'UTC'))->pluck('id')->all())->toBe([])
        ->and($dates->applyGameDateWindow(Game::query(), $dates->forDate('2026-07-02'))->pluck('id')->all())->toBe([])
        ->and(app(SnapshotProvenanceResolver::class)->gameStartAt($game))->toBeNull();
    $resource = (new SportGameResource($game, app(SportContextResolver::class)->resolve('mlb')))->resolve(request());
    expect($resource['starts_at'])->toBeNull()->and($resource['start_time_provenance'])->toBe('observed_start_invalid');
})->with([
    [['game_time' => '02:31:00']],
    [['espn_event_id' => 'different-observed-event']],
    [['start_context_hash' => str_repeat('f', 64)]],
    [['start_source_hash' => null]],
    [['start_source_hash' => str_repeat('e', 64)]],
    [['start_observed_at' => null]],
    [['start_observed_at' => 'not-a-timestamp']],
    [['start_time_version' => null]],
    [['game_time' => 'not-a-time']],
]);

it('retains legacy calendar inventory while declining unknown precise start and time-sensitive features', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-07-02', 'game_time' => '12:30:00']);
    $dates = app(SportsDateWindowService::class);
    expect($dates->applyGameDateWindow(Game::query(), $dates->forDate('2026-07-02'))->pluck('id')->all())->toContain($game->id)
        ->and(MlbGameStart::for($game))->toBeNull()
        ->and(app(MlbPeriodFeatureBuilder::class)->liveFeatures($game))->toBe([])
        ->and(app(MlbPeriodFeatureBuilder::class)->liveFeaturesForGames(collect([$game])))->toBe([]);
});

it('preserves a known omitted observation only while bound identity and wall fields remain unchanged', function () {
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    $before = $game->getAttributes();
    $game->update(MlbGameStart::prepare($game, ['game_date' => null, 'game_time' => null, 'venue_city' => 'New observed venue text']));
    expect(MlbGameStart::for($game)->getTimestamp())->toBe((int) $before['start_epoch'])
        ->and($game->start_source_hash)->toBe($before['start_source_hash']);
    $game->update(MlbGameStart::prepare($game, ['game_time' => '02:31:00']));
    expect(MlbGameStart::for($game))->toBeNull();
});

it('does not backdate newly observed schedule availability to an old prediction capture', function () {
    $this->travelTo(Carbon::parse('2026-07-03T12:00:00Z'));
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    $provenance = app(SnapshotProvenanceResolver::class)->resolve($game, [], Carbon::parse('2026-07-02T05:00:00Z'));
    expect($provenance['pregame_safe'])->toBeFalse()
        ->and($provenance['availability_status'])->not->toBe('observed_pregame')
        ->and(MlbGameStart::observedBy($game, Carbon::parse('2026-07-02T05:00:00Z')))->toBeFalse();
});

it('preserves an unverified legacy canonical time on omitted observation and changes it only with valid observation', function () {
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'starts_at' => Carbon::parse('2026-07-02 01:30:00', 'America/Chicago')]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $before = $event->fresh()->getRawOriginal('starts_at');
    $identity = app(SportEventIdentitySynchronizer::class);
    $identity->mutateAndSync('mlb', $game, fn ($locked) => $locked->update(MlbGameStart::prepare($locked, ['game_date' => null, 'game_time' => null, 'venue_name' => 'Observed name only'])));
    expect($event->fresh()->getRawOriginal('starts_at'))->toBe($before)->and(MlbGameStart::for($game->fresh()))->toBeNull();
    expect(fn () => MlbGameStart::observed('2026-02-30T00:00:00Z', []))->toThrow(InvalidArgumentException::class);
    expect($event->fresh()->getRawOriginal('starts_at'))->toBe($before);
    $attributes = ['game_date' => '2026-07-02', 'game_time' => '03:30:00'] + MlbGameStart::observed('2026-07-02T08:30:00Z', ['date' => '2026-07-02T08:30:00Z']);
    $identity->mutateAndSync('mlb', $game->fresh(), fn ($locked) => $locked->update(MlbGameStart::prepare($locked, $attributes)));
    expect($game->fresh()->sportEvent->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-02 08:30:00');
});

it('rolls back the local observed start when actual canonical persistence fails', function () {
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes();
    $identity = app(SportEventIdentitySynchronizer::class);
    $game = $identity->createAndSync('mlb', Game::class, $attributes);
    $beforeGame = $game->fresh()->getAttributes();
    $beforeEvent = $game->fresh()->sportEvent->getAttributes();
    $fail = true;
    $game->getConnection()->beforeExecuting(function (string $query) use (&$fail): void {
        if ($fail && str_starts_with(strtolower(ltrim($query)), 'update') && str_contains($query, 'sport_events')) {
            throw new RuntimeException('synthetic canonical persistence failure');
        }
    });
    try {
        $changes = ['game_time' => '03:30:00'] + MlbGameStart::observed('2026-07-02T07:30:00Z', ['date' => '2026-07-02T07:30:00Z']);
        expect(fn () => $identity->mutateAndSync('mlb', $game, fn ($locked) => $locked->update(MlbGameStart::prepare($locked, $changes))))->toThrow(RuntimeException::class, 'synthetic canonical persistence failure');
    } finally {
        $fail = false;
    }
    expect($game->fresh()->getAttributes())->toBe($beforeGame)->and($game->fresh()->sportEvent->getAttributes())->toBe($beforeEvent);
});

function mlbObservedStartPayload(mixed $instant): array
{
    return [
        'id' => 'synthetic-time-event', 'date' => $instant, 'season' => ['year' => 2026, 'type' => 2], 'week' => ['number' => 1],
        'competitions' => [[
            'date' => $instant, 'venue' => ['address' => ['city' => 'Los Angeles', 'state' => 'CA']],
            'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
            'competitors' => [['homeAway' => 'home', 'team' => ['id' => 'synthetic-home']], ['homeAway' => 'away', 'team' => ['id' => 'synthetic-away']]],
        ]],
    ];
}

function runMlbObservedProducer(string $route, array $payload): void
{
    $service = new class($payload) extends EspnService
    {
        public function __construct(private array $payload)
        {
            parent::__construct();
        }

        public function getGames(int $season, int $seasonType, int $week): ?array
        {
            return ['items' => [$this->payload]];
        }

        public function getSchedule(string $teamId, ?int $season = null): ?array
        {
            return ['events' => [$this->payload]];
        }

        public function getScoreboard(?string $date = null): ?array
        {
            return ['events' => [$this->payload]];
        }

        public function getGame(string $eventId): ?array
        {
            return ['header' => ['id' => $eventId, 'competitions' => $this->payload['competitions']]];
        }
    };
    $live = new class
    {
        public function execute($game): void {}
    };
    if ($route === 'games') {
        (new SyncGames($service))->execute(2026, 2, 1);
    } elseif ($route === 'schedule') {
        (new SyncGamesFromSchedule($service))->execute('synthetic-home', 2026);
    } elseif ($route === 'scoreboard') {
        (new SyncGamesFromScoreboard($service, $live))->execute('20260702');
    } elseif ($route === 'summary') {
        $action = new class($service, $live) extends SyncGamesFromScoreboard
        {
            public function apply(array $payload, Game $game): void
            {
                $this->updateGameFromSummary(['header' => ['id' => $game->espn_event_id, 'competitions' => $payload['competitions']]], $game);
            }
        };
        $action->apply($payload, Game::where('espn_event_id', $payload['id'])->firstOrFail());
    } else {
        $player = Mockery::mock(SyncPlayerStats::class)->shouldIgnoreMissing(0);
        $team = Mockery::mock(SyncTeamStats::class)->shouldIgnoreMissing(0);
        $plays = Mockery::mock(SyncPlays::class)->shouldIgnoreMissing(0);
        (new SyncGameDetails($service, $player, $team, $plays))->execute($payload['id']);
    }
}

it('persists an explicit observed instant through each MLB ingestion adapter', function (string $route) {
    Http::preventStrayRequests();
    Queue::fake();
    $home = Team::factory()->create(['espn_id' => 'synthetic-home']);
    $away = Team::factory()->create(['espn_id' => 'synthetic-away']);
    if (in_array($route, ['summary', 'details'], true)) {
        Game::factory()->create(['espn_event_id' => 'synthetic-time-event', 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED']);
    }
    runMlbObservedProducer($route, mlbObservedStartPayload('2026-07-02T06:30:00Z'));
    $game = Game::where('espn_event_id', 'synthetic-time-event')->firstOrFail();
    expect(MlbGameStart::for($game)?->format('Y-m-d H:i:s'))->toBe('2026-07-02 06:30:00')
        ->and($game->game_date->toDateString())->toBe('2026-07-01')->and($game->game_time)->toBe('23:30:00')
        ->and($game->sportEvent->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-07-02 06:30:00');
    Http::assertNothingSent();
})->with(['games', 'schedule', 'scoreboard', 'summary', 'details']);

it('refuses invalid supplied instants before mutating an existing game or event in each MLB adapter', function (string $route) {
    Http::preventStrayRequests();
    Queue::fake();
    $home = Team::factory()->create(['espn_id' => 'synthetic-home']);
    $away = Team::factory()->create(['espn_id' => 'synthetic-away']);
    $attributes = Game::factory()->make(['espn_event_id' => 'synthetic-time-event', 'home_team_id' => $home->id, 'away_team_id' => $away->id] + array_diff_key(mlbSyntheticVenueDateParts(null, null), ['home_team_id' => true, 'away_team_id' => true]))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    $beforeGame = $game->fresh()->getAttributes();
    $beforeEvent = $game->fresh()->sportEvent->getAttributes();
    expect(fn () => runMlbObservedProducer($route, mlbObservedStartPayload('2026-02-30T06:30:00Z')))->toThrow(InvalidArgumentException::class);
    expect($game->fresh()->getAttributes())->toBe($beforeGame)->and($game->fresh()->sportEvent->getAttributes())->toBe($beforeEvent);
    Http::assertNothingSent();
})->with(['games', 'schedule', 'scoreboard', 'summary', 'details']);

it('preserves the original fact observation on an equivalent refresh and does not recertify old rows', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $attributes = Game::factory()->make(mlbSyntheticVenueDateParts('Boston', 'MA'))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    $observed = $game->start_observed_at;
    $this->travelTo(Carbon::parse('2026-07-01T07:00:00Z'));
    $changes = MlbGameStart::observed('2026-07-02T15:30:00+09:00', ['status' => 'different', 'header' => ['id' => $game->espn_event_id], 'date' => '2026-07-02T15:30:00+09:00']);
    $game->update(MlbGameStart::prepare($game, $changes));
    expect($game->start_observed_at)->toBe($observed)->and(MlbGameStart::observedBy($game, Carbon::parse('2026-07-01T06:30:00Z')))->toBeTrue();
});

it('does not let caller-provided verified flags override unknown schedule availability', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id]);
    $result = app(SnapshotProvenanceResolver::class)->resolve($game, ['point_in_time_verified' => true, 'pregame_safe' => true, 'availability_status' => 'verified_reconstruction'], now());
    expect($result['pregame_safe'])->toBeFalse()->and($result['availability_status'])->toBe('game_start_unknown')
        ->and($result['lineage_metadata']['point_in_time_verified'])->toBeFalse();
});

it('refuses retrospective training and requires explicit descriptive export admission', function () {
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldReceive('historicalRows')->andReturn(collect([['game_id' => 1, 'pregame_safe' => false, 'availability_status' => 'reconstructed_now']]));
    app()->instance(MlbPeriodFeatureBuilder::class, $builder);
    $path = storage_path('framework/testing/mlb-retrospective-'.Str::uuid().'.csv');
    $this->artisan('mlb:export-period-training-data', ['--path' => $path])->assertFailed();
    expect(file_exists($path))->toBeFalse();
    $this->artisan('mlb:train-period-models', ['--no-register' => true])->expectsOutput('Retrospective or unknown-availability rows cannot enter point-in-time model training.')->assertFailed();
    expect(ModelArtifact::count())->toBe(0);
    try {
        $this->artisan('mlb:export-period-training-data', ['--path' => $path, '--retrospective' => true])->assertSuccessful();
        expect(file_get_contents($path))->toContain('reconstructed_now');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('declines old artifact timing policy before attempting materialization or inference', function () {
    $registry = Mockery::mock(ModelArtifactRegistry::class);
    $registry->shouldNotReceive('materializeArtifact');
    $artifact = new ModelArtifact(['sport' => 'mlb', 'model_type' => 'mlb_period_bundle', 'status' => 'promoted']);
    $artifact->setRelation('trainingRun', new ModelRun(['metadata' => ['python_manifest' => []]]));
    $before = $artifact->getAttributes();
    expect(fn () => (new MlbPeriodModelInferenceService($registry))->predict($artifact, []))->toThrow(RuntimeException::class, 'observed-start/source-availability policy');
    expect($artifact->getAttributes())->toBe($before)->and(MlbGameStart::artifactCompatible($artifact))->toBeFalse();
    $artifact->setRelation('trainingRun', new ModelRun(['metadata' => ['python_manifest' => ['timing_policy' => MlbGameStart::TIMING_POLICY, 'source_availability_verified' => true]]]));
    expect(MlbGameStart::artifactCompatible($artifact))->toBeTrue();
});

it('preserves a known observed start when an existing MLB adapter receives no schedule time', function (string $route) {
    Http::preventStrayRequests();
    Queue::fake();
    $home = Team::factory()->create(['espn_id' => 'synthetic-home']);
    $away = Team::factory()->create(['espn_id' => 'synthetic-away']);
    $attributes = Game::factory()->make(['espn_event_id' => 'synthetic-time-event', 'status' => 'STATUS_SCHEDULED', 'home_team_id' => $home->id, 'away_team_id' => $away->id]
        + array_diff_key(mlbSyntheticVenueDateParts(null, null), ['home_team_id' => true, 'away_team_id' => true]))->getAttributes();
    $game = app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes);
    $before = array_intersect_key($game->fresh()->getAttributes(), array_flip(['game_date', 'game_time', ...MlbGameStart::FIELDS]));
    $eventStart = $game->sportEvent->getRawOriginal('starts_at');
    $payload = mlbObservedStartPayload(null);
    unset($payload['date'], $payload['competitions'][0]['date']);
    runMlbObservedProducer($route, $payload);
    expect(array_intersect_key($game->fresh()->getAttributes(), array_flip(['game_date', 'game_time', ...MlbGameStart::FIELDS])))->toBe($before)
        ->and($game->fresh()->sportEvent->getRawOriginal('starts_at'))->toBe($eventStart);
    Http::assertNothingSent();
})->with(['games', 'schedule', 'scoreboard', 'summary', 'details']);

it('refuses a new MLB game with omitted schedule time instead of inventing the current time', function (string $route) {
    Http::preventStrayRequests();
    Queue::fake();
    Team::factory()->create(['espn_id' => 'synthetic-home']);
    Team::factory()->create(['espn_id' => 'synthetic-away']);
    $payload = mlbObservedStartPayload(null);
    unset($payload['date'], $payload['competitions'][0]['date']);
    expect(fn () => runMlbObservedProducer($route, $payload))->toThrow(InvalidArgumentException::class);
    expect(Game::count())->toBe(0)->and(SportEvent::count())->toBe(0);
    Http::assertNothingSent();
})->with(['games', 'schedule', 'scoreboard']);

it('accepts explicit offset minute precision without accepting naive or normalized-invalid calendars', function () {
    expect(MlbGameStart::observed('2026-04-03T17:05Z', [])['start_epoch'])->toBe(MlbGameStart::observed('2026-04-03T17:05:00Z', [])['start_epoch'])
        ->and(MlbGameStart::observed('2026-04-03T12:05-05:00', [])['start_epoch'])->toBe(MlbGameStart::observed('2026-04-03T17:05:00Z', [])['start_epoch']);
    foreach (['2026-04-03T17:05', '2026-02-30T17:05Z', '2026-04-03T25:05Z', '2026-04-03T17:65Z', '2026-04-03T17:05.5Z'] as $bad) {
        expect(fn () => MlbGameStart::observed($bad, []))->toThrow(InvalidArgumentException::class);
    }
});
