<?php

use App\Actions\ESPN\NFL\SyncPlayerInjuries;
use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Models\ProviderRefreshAttempt;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/ProviderRefreshFixture.php';

function refreshHistorySource(): BaseEspnService
{
    return new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public function getTeamInjuries(string $teamId): ?array
        {
            return ['items' => [['id' => 'known', 'athlete' => ['id' => '123'], 'status' => 'Out']]];
        }

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            return ['items' => [['positions' => ['qb' => ['athletes' => [['rank' => 1, 'athlete' => ['id' => '123']]]]]]]];
        }
    };
}

it('links only the actually produced or reused NFL injury history and restricts parent deletion', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    enableSyntheticRefreshScope($team, 'injuries', 0);
    $action = new SyncPlayerInjuries(refreshHistorySource());
    $action->execute('22');
    $action->execute('22');
    $snapshot = PlayerInjurySnapshot::query()->sole();
    expect(ProviderRefreshAttempt::query()->pluck('nfl_injury_snapshot_id')->all())->toBe([$snapshot->id, $snapshot->id])
        ->and(ProviderRefreshAttempt::query()->whereNotNull('nfl_depth_snapshot_id')->count())->toBe(0);
    expect(fn () => DB::table('nfl_player_injury_snapshots')->where('id', $snapshot->id)->delete())->toThrow(QueryException::class);
});

it('links the actually produced NFL depth history in the projection transaction', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    enableSyntheticRefreshScope($team, 'depth_chart', 2026);
    (new SyncTeamDepthCharts(refreshHistorySource()))->execute('22', 2026);
    expect(ProviderRefreshAttempt::query()->sole()->nfl_depth_snapshot_id)->toBe(DepthChartSnapshot::query()->sole()->id)
        ->and(ProviderRefreshAttempt::query()->sole()->nfl_injury_snapshot_id)->toBeNull();
});

it('does not manufacture historical pointers for a sport without that archive', function () {
    $team = App\Models\CFB\Team::factory()->create(['espn_id' => '22']);
    App\Models\CFB\Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    enableSyntheticRefreshScope($team, 'injuries', 0);
    (new App\Actions\ESPN\CFB\SyncPlayerInjuries(refreshHistorySource()))->execute('22');
    $attempt = ProviderRefreshAttempt::query()->sole();
    expect($attempt->outcome)->toBe('applied')->and($attempt->source_fingerprint)->toHaveLength(64)
        ->and($attempt->nfl_injury_snapshot_id)->toBeNull()->and($attempt->nfl_depth_snapshot_id)->toBeNull();
});

it('does not retain a dangling history pointer when projection insertion rolls back', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    enableSyntheticRefreshScope($team, 'injuries', 0);
    $armed = true;
    DB::listen(function ($query) use (&$armed): void {
        $sql = str_replace(['`', '"'], '', strtolower($query->sql));
        if ($armed && str_starts_with($sql, 'insert into nfl_player_injuries ')) {
            $armed = false;
            throw new RuntimeException('Synthetic projection failure.');
        }
    });
    try {
        expect(fn () => (new SyncPlayerInjuries(refreshHistorySource()))->execute('22'))->toThrow(RuntimeException::class, 'Synthetic projection failure.');
    } finally {
        $armed = false;
    }
    expect(PlayerInjurySnapshot::query()->count())->toBe(0)
        ->and(ProviderRefreshAttempt::query()->sole()->outcome)->toBe('failed')
        ->and(ProviderRefreshAttempt::query()->sole()->nfl_injury_snapshot_id)->toBeNull();
});
