<?php

use App\Actions\ESPN\NFL\SyncPlayerInjuries;
use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;

require_once __DIR__.'/../../Support/ProviderRefreshFixture.php';

it('never applies an older injury response after a newer acquisition has completed or failed', function (bool $newerFails) {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    PlayerInjury::create(['team_id' => $team->id, 'player_id' => $player->id, 'injury_key' => 'espn:known', 'status' => 'Out', 'is_active' => true]);
    enableSyntheticRefreshScope($team, 'injuries', 0);
    $service = new class($newerFails) extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        private int $calls = 0;

        public function __construct(private bool $newerFails) {}

        public function getTeamInjuries(string $teamId): ?array
        {
            if (++$this->calls === 1) {
                (new SyncPlayerInjuries($this))->execute($teamId);
                $status = 'Questionable';
            } else {
                if ($this->newerFails) {
                    return null;
                }
                $status = 'Doubtful';
            }

            return ['items' => [['id' => 'known', 'athlete' => ['id' => '123'], 'status' => $status]]];
        }

        public function getRoster(string $teamId): ?array
        {
            return null;
        }
    };
    (new SyncPlayerInjuries($service))->execute('22');
    expect(PlayerInjury::query()->sole()->status)->toBe($newerFails ? 'Out' : 'Doubtful');
})->with([false, true]);

it('never applies an older depth response after a newer acquisition has completed or failed', function (bool $newerFails) {
    $team = Team::factory()->create(['espn_id' => '22']);
    Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    DepthChartEntry::create(['team_id' => $team->id, 'season' => 2026, 'espn_athlete_id' => '123', 'position_slot_key' => 'qb', 'depth_rank' => 1, 'is_starter' => true]);
    enableSyntheticRefreshScope($team, 'depth_chart', 2026);
    $service = new class($newerFails) extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        private int $calls = 0;

        public function __construct(private bool $newerFails) {}

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            if (++$this->calls === 1) {
                try {
                    (new SyncTeamDepthCharts($this))->execute($teamId, $season);
                } catch (RuntimeException $e) {
                    if (! $this->newerFails) {
                        throw $e;
                    }
                }
                $rank = 2;
            } else {
                if ($this->newerFails) {
                    return null;
                }
                $rank = 3;
            }

            return ['items' => [['positions' => ['qb' => ['athletes' => [['rank' => $rank, 'athlete' => ['id' => '123']]]]]]]];
        }
    };
    (new SyncTeamDepthCharts($service))->execute('22', 2026);
    expect((int) DepthChartEntry::query()->sole()->depth_rank)->toBe($newerFails ? 1 : 3);
})->with([false, true]);
