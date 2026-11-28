<?php

use App\Actions\ESPN\AbstractSyncTeamDepthCharts;
use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Models\NBA\Player;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Support\Facades\DB;

function depthChartIntegritySource(): BaseEspnService
{
    return new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public ?array $payload = ['items' => [['positions' => ['qb' => [
            'position' => ['abbreviation' => 'QB'],
            'athletes' => [['rank' => 1, 'athlete' => ['id' => '123']]],
        ]]]]];

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            return $this->payload;
        }
    };
}

it('rejects malformed nested charts without deleting prior projection or appending an observation', function (array $payload) {
    $team = Team::factory()->create(['espn_id' => '22']);
    $source = depthChartIntegritySource();
    $action = new SyncTeamDepthCharts($source);
    $action->execute('22', 2026);
    $before = DepthChartEntry::where('team_id', $team->id)->sole()->getRawOriginal();
    $source->payload = $payload;

    expect(fn () => $action->execute('22', 2026))->toThrow(RuntimeException::class);
    expect(DepthChartEntry::where('team_id', $team->id)->sole()->getRawOriginal())->toBe($before)
        ->and(DepthChartSnapshot::where('team_id', $team->id)->count())->toBe(1);
})->with([
    'nonobject chart' => [['items' => ['bad']]],
    'missing positions' => [['items' => [['name' => 'Offense']]]],
    'invalid slot' => [['items' => [['positions' => ['qb' => null]]]]],
    'missing athletes' => [['items' => [['positions' => ['qb' => ['position' => []]]]]]],
    'missing athlete identity' => [['items' => [['positions' => ['qb' => ['athletes' => [['rank' => 1]]]]]]]],
    'invalid rank' => [['items' => [['positions' => ['qb' => ['athletes' => [['rank' => 'invalid', 'athlete' => ['id' => '123']]]]]]]]],
]);

it('rolls back both projection replacement and its historical observation if insertion fails', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $source = depthChartIntegritySource();
    $action = new SyncTeamDepthCharts($source);
    $action->execute('22', 2026);
    $before = DepthChartEntry::where('team_id', $team->id)->sole()->getRawOriginal();
    $armed = true;
    DB::listen(function ($query) use (&$armed): void {
        $sql = str_replace(['`', '"'], '', strtolower($query->sql));
        if ($armed && str_starts_with($sql, 'insert into nfl_depth_chart_entries ')) {
            $armed = false;
            throw new RuntimeException('Synthetic projection insertion failure.');
        }
    });
    try {
        expect(fn () => $action->execute('22', 2026))->toThrow(RuntimeException::class, 'Synthetic projection insertion failure.');
    } finally {
        $armed = false;
    }
    expect(DepthChartEntry::where('team_id', $team->id)->sole()->getRawOriginal())->toBe($before)
        ->and(DepthChartSnapshot::where('team_id', $team->id)->count())->toBe(1);
});

it('preserves an explicit empty chart as an atomic observed clearing', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $source = depthChartIntegritySource();
    $action = new SyncTeamDepthCharts($source);
    $action->execute('22', 2026);
    $source->payload = ['items' => []];
    expect($action->execute('22', 2026))->toBe(0)
        ->and(DepthChartEntry::where('team_id', $team->id)->count())->toBe(0)
        ->and(DepthChartSnapshot::where('team_id', $team->id)->count())->toBe(2);
});

it('refuses a changed team identity after fetching without replacing prior observations', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $source = depthChartIntegritySource();
    (new SyncTeamDepthCharts($source))->execute('22', 2026);
    $before = DepthChartEntry::where('team_id', $team->id)->sole()->getRawOriginal();
    $racing = new class($source->payload, $team) extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public function __construct(private array $payload, private Team $team) {}

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            $this->team->update(['espn_id' => '99']);

            return $this->payload;
        }
    };
    expect(fn () => (new SyncTeamDepthCharts($racing))->execute('22', 2026))->toThrow(RuntimeException::class, 'team identity changed');
    expect(DepthChartEntry::where('team_id', $team->id)->sole()->getRawOriginal())->toBe($before)
        ->and(DepthChartSnapshot::where('team_id', $team->id)->count())->toBe(1);
});

class NamedDepthChartTeam extends App\Models\NBA\Team
{
    protected $connection = 'sqlite';
}
class NamedDepthChartPlayer extends Player
{
    protected $connection = 'sqlite';
}
class NamedDepthChartEntry extends App\Models\NBA\DepthChartEntry
{
    protected $connection = 'sqlite';
}

it('replaces on the explicit model connection even when the default database has no tables', function () {
    App\Models\NBA\Team::factory()->create(['espn_id' => '22']);
    $source = depthChartIntegritySource();
    $action = new class($source) extends AbstractSyncTeamDepthCharts
    {
        protected const TEAM_MODEL_CLASS = NamedDepthChartTeam::class;

        protected const PLAYER_MODEL_CLASS = NamedDepthChartPlayer::class;

        protected const DEPTH_CHART_MODEL_CLASS = NamedDepthChartEntry::class;
    };
    config(['database.connections.depth_poison' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $prior = config('database.default');
    DB::setDefaultConnection('depth_poison');
    try {
        expect($action->execute('22', 2026))->toBe(1)
            ->and(NamedDepthChartEntry::query()->count())->toBe(1)
            ->and(DB::connection('depth_poison')->getSchemaBuilder()->hasTable('nba_depth_chart_entries'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($prior);
        DB::purge('depth_poison');
    }
})->skip(fn () => config('database.default') !== 'sqlite', 'Explicit SQLite alternate-default connection contract.');
