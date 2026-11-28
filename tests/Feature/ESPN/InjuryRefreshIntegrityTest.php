<?php

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Actions\ESPN\NFL\SyncPlayerInjuries;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function injuryIntegritySource(): BaseEspnService
{
    return new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public ?array $injuries = ['items' => [['id' => 'known', 'athlete' => ['id' => '123'], 'status' => 'Out']]];

        public ?array $roster = null;

        public function getTeamInjuries(string $teamId): ?array
        {
            return $this->injuries;
        }

        public function getRoster(string $teamId): ?array
        {
            return $this->roster;
        }
    };
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->team = Team::factory()->create(['espn_id' => '22']);
    Player::factory()->create(['team_id' => $this->team->id, 'espn_id' => '123']);
});

it('does not deactivate known injuries from malformed or explicitly incomplete observations', function (?array $injuries, ?array $roster) {
    $source = injuryIntegritySource();
    $action = new SyncPlayerInjuries($source);
    $action->execute('22');
    $source->injuries = $injuries;
    $source->roster = $roster;
    $action->execute('22');
    expect(PlayerInjury::query()->sole()->is_active)->toBeTrue();
})->with([
    'unrecognized objects' => [[], []],
    'scalar collection' => [['items' => 'bad'], null],
    'invalid collection entries' => [['items' => [null]], null],
    'missing roster injury coverage' => [null, ['athletes' => [['id' => '123']]]],
    'truncated source count' => [['items' => [], 'total' => 2], null],
]);

it('keeps an explicit complete empty source authoritative', function () {
    $source = injuryIntegritySource();
    $action = new SyncPlayerInjuries($source);
    $action->execute('22');
    $source->injuries = ['items' => []];
    $action->execute('22');
    expect(PlayerInjury::query()->sole()->is_active)->toBeFalse();
});

it('rolls back the NFL observation when its projection write fails', function () {
    $source = injuryIntegritySource();
    $action = new SyncPlayerInjuries($source);
    $action->execute('22');
    $source->injuries['items'][0]['status'] = 'Questionable';
    $before = PlayerInjury::query()->sole()->getRawOriginal();
    $armed = true;
    DB::listen(function ($query) use (&$armed): void {
        $sql = str_replace(['`', '"'], '', strtolower($query->sql));
        if ($armed && str_starts_with($sql, 'insert into nfl_player_injuries ')) {
            $armed = false;
            throw new RuntimeException('Synthetic injury projection failure.');
        }
    });
    try {
        expect(fn () => $action->execute('22'))->toThrow(RuntimeException::class, 'Synthetic injury projection failure.');
    } finally {
        $armed = false;
    }
    expect(PlayerInjury::query()->sole()->getRawOriginal())->toBe($before)
        ->and(PlayerInjurySnapshot::query()->count())->toBe(1);
});

it('refuses unapproved injury references before any HTTP request', function (string $url) {
    Http::fake(['*' => Http::response(['athlete' => ['id' => '123'], 'status' => 'Out'])]);
    $source = injuryIntegritySource();
    $source->injuries = ['items' => [['$ref' => $url]]];
    (new SyncPlayerInjuries($source))->execute('22');
    Http::assertNothingSent();
})->with(['http://127.0.0.1/private', 'https://example.invalid/injury', 'https://sports.core.api.espn.com@127.0.0.1/private']);

it('upgrades approved HTTP references through the existing safe transport', function () {
    Http::fake(['https://sports.core.api.espn.com/*' => Http::response(['id' => 'known', 'athlete' => ['id' => '123'], 'status' => 'Out'])]);
    $source = injuryIntegritySource();
    $source->injuries = ['items' => [['$ref' => 'http://sports.core.api.espn.com/v2/sports/football/leagues/nfl/injuries/1']]];
    expect((new SyncPlayerInjuries($source))->execute('22'))->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://sports.core.api.espn.com/'));
});

it('does not reuse a prior acquisition reference when the same action observes a new source', function () {
    $url = 'https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/injuries/1';
    Http::fake([$url => Http::sequence()->push(['id' => 'known', 'athlete' => ['id' => '123'], 'status' => 'Out'])
        ->push(['id' => 'known', 'athlete' => ['id' => '123'], 'status' => 'Questionable'])]);
    $source = injuryIntegritySource();
    $source->injuries = ['items' => [['$ref' => $url]]];
    $action = new SyncPlayerInjuries($source);
    $action->execute('22');
    $action->execute('22');
    expect(PlayerInjury::query()->sole()->status)->toBe('Questionable');
    Http::assertSentCount(2);
});

it('preserves known injuries for malformed objects across sport inheritors', function (string $sport) {
    config(['nba.injuries.observations_enabled' => false]);
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $playerClass = 'App\\Models\\'.$sport.'\\Player';
    $injuryClass = 'App\\Models\\'.$sport.'\\PlayerInjury';
    $actionClass = 'App\\Actions\\ESPN\\'.$sport.'\\SyncPlayerInjuries';
    $team = $teamClass::factory()->create(['espn_id' => '44']);
    $player = $playerClass::factory()->create(['team_id' => $team->id, 'espn_id' => '444']);
    $injuryClass::query()->create(['team_id' => $team->id, 'player_id' => $player->id,
        'injury_key' => 'known', 'status' => 'Out', 'is_active' => true]);
    $source = injuryIntegritySource();
    $source->injuries = [];
    $source->roster = [];
    (new $actionClass($source))->execute('44');
    expect($injuryClass::query()->where('team_id', $team->id)->sole()->is_active)->toBeTrue();
})->with(['NFL', 'NBA', 'CBB', 'CFB', 'MLB', 'WNBA', 'WCBB']);

it('refuses changed provider team identity between fetch and projection', function () {
    $source = injuryIntegritySource();
    (new SyncPlayerInjuries($source))->execute('22');
    $before = PlayerInjury::query()->sole()->getRawOriginal();
    $racing = new class($source->injuries, $this->team) extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public function __construct(private array $payload, private Team $team) {}

        public function getTeamInjuries(string $teamId): ?array
        {
            $this->team->update(['espn_id' => '99']);

            return $this->payload;
        }
    };
    expect(fn () => (new SyncPlayerInjuries($racing))->execute('22'))->toThrow(RuntimeException::class, 'team identity changed');
    expect(PlayerInjury::query()->sole()->getRawOriginal())->toBe($before)
        ->and(PlayerInjurySnapshot::query()->count())->toBe(1);
});

it('does not replace a known injury with an entry missing its observed status', function () {
    $source = injuryIntegritySource();
    $action = new SyncPlayerInjuries($source);
    $action->execute('22');
    unset($source->injuries['items'][0]['status']);
    $action->execute('22');
    expect(PlayerInjury::query()->sole()->status)->toBe('Out')
        ->and(PlayerInjury::query()->sole()->is_active)->toBeTrue()
        ->and(PlayerInjurySnapshot::query()->count())->toBe(1);
});

class NamedInjuryTeam extends Team
{
    protected $connection = 'sqlite';
}
class NamedInjuryPlayer extends Player
{
    protected $connection = 'sqlite';
}

it('uses the coherent named model connection for projection and unchanged-value comparison', function () {
    $action = new class(injuryIntegritySource()) extends AbstractSyncPlayerInjuries
    {
        protected const TEAM_MODEL_CLASS = NamedInjuryTeam::class;

        protected const PLAYER_MODEL_CLASS = NamedInjuryPlayer::class;

        protected const INJURY_TABLE = 'nfl_player_injuries';
    };
    config(['database.connections.injury_poison' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $prior = config('database.default');
    DB::setDefaultConnection('injury_poison');
    try {
        expect($action->execute('22'))->toBe(1)
            ->and($action->execute('22'))->toBe(1)
            ->and(DB::connection('sqlite')->table('nfl_player_injuries')->count())->toBe(1)
            ->and(DB::connection('injury_poison')->getSchemaBuilder()->hasTable('nfl_player_injuries'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($prior);
        DB::purge('injury_poison');
    }
})->skip(fn () => config('database.default') !== 'sqlite', 'Explicit SQLite alternate-default connection contract.');

it('refuses duplicate player injury identities before projection or historical observation', function (bool $contradictory) {
    $source = injuryIntegritySource();
    $action = new SyncPlayerInjuries($source);
    $action->execute('22');
    $before = PlayerInjury::query()->sole()->getRawOriginal();
    $source->injuries['items'][] = $source->injuries['items'][0];
    if ($contradictory) {
        $source->injuries['items'][1]['status'] = 'Questionable';
    }
    expect(fn () => $action->execute('22'))->toThrow(RuntimeException::class, 'Duplicate injury identity');
    expect(PlayerInjury::query()->sole()->getRawOriginal())->toBe($before)
        ->and(PlayerInjurySnapshot::query()->count())->toBe(1);
})->with([false, true]);

it('refuses NFL partial projection when the legacy history cannot record incomplete coverage', function () {
    $source = injuryIntegritySource();
    $action = new SyncPlayerInjuries($source);
    $action->execute('22');
    $before = PlayerInjury::query()->sole()->getRawOriginal();
    $source->injuries['items'][0]['status'] = 'Questionable';
    $source->injuries['total'] = 2;
    expect($action->execute('22'))->toBe(0)
        ->and($action->lastSyncReliable())->toBeFalse()
        ->and(PlayerInjury::query()->sole()->getRawOriginal())->toBe($before)
        ->and(PlayerInjurySnapshot::query()->count())->toBe(1);
});
