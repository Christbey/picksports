<?php

use App\Actions\ESPN\MLB\SyncGames as SyncMlbGames;
use App\Actions\ESPN\WNBA\SyncGamesFromScoreboard as SyncWnbaGamesFromScoreboard;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Models\WNBA\Game as WnbaGame;
use App\Models\WNBA\Team as WnbaTeam;
use App\Services\ESPN\BaseEspnService;
use App\Services\ESPN\WNBA\EspnService as WnbaEspnService;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

it('dual writes canonical identities from common ESPN lifecycles across sports without duplicates', function () {
    MlbTeam::factory()->create(['espn_id' => '10']);
    MlbTeam::factory()->create(['espn_id' => '20']);
    WnbaTeam::factory()->create(['espn_id' => '24']);
    WnbaTeam::factory()->create(['espn_id' => '26']);

    $mlbService = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'mlb';

        public function getGames(int $season, int $seasonType, int $week): ?array
        {
            return ['items' => [[
                'id' => '401990001',
                'uid' => 's:1~l:10~e:401990001',
                'date' => '2026-06-10T18:05:00Z',
                'name' => 'Away at Home',
                'shortName' => 'AWY @ HOM',
                'season' => ['year' => $season, 'type' => $seasonType],
                'week' => ['number' => $week],
                'competitions' => [[
                    'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
                    'competitors' => [
                        ['homeAway' => 'home', 'team' => ['id' => '10']],
                        ['homeAway' => 'away', 'team' => ['id' => '20']],
                    ],
                ]],
            ]]];
        }
    };
    $wnbaService = new class extends WnbaEspnService
    {
        public function getScoreboard(?string $date = null): ?array
        {
            return ['events' => [[
                'id' => '401990002',
                'uid' => 's:40~l:59~e:401990002',
                'date' => '2026-08-01T02:00:00Z',
                'name' => 'Away at Home',
                'shortName' => 'AWY @ HOM',
                'season' => ['year' => 2026, 'type' => 2],
                'week' => ['number' => 12],
                'competitions' => [[
                    'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
                    'competitors' => [
                        ['homeAway' => 'home', 'team' => ['id' => '26']],
                        ['homeAway' => 'away', 'team' => ['id' => '24']],
                    ],
                ]],
            ]]];
        }
    };
    $livePrediction = new class
    {
        public function execute(WnbaGame $game): void {}
    };

    $mlbSync = new SyncMlbGames($mlbService);
    $wnbaSync = new SyncWnbaGamesFromScoreboard($wnbaService, $livePrediction);

    expect($mlbSync->execute(2026, 2, 12))->toBe(1)
        ->and($wnbaSync->execute('20260801'))->toBe(1)
        ->and($mlbSync->execute(2026, 2, 12))->toBe(1)
        ->and($wnbaSync->execute('20260801'))->toBe(1);

    $mlbGame = MlbGame::query()->where('espn_event_id', '401990001')->firstOrFail();
    $wnbaGame = WnbaGame::query()->where('espn_event_id', '401990002')->firstOrFail();

    expect($mlbGame->sportEvent?->sport)->toBe('mlb')
        ->and($wnbaGame->sportEvent?->sport)->toBe('wnba')
        ->and($mlbGame->sportEvent?->starts_at?->utc()->toIso8601String())->toBe('2026-06-10T18:05:00+00:00')
        ->and($wnbaGame->sportEvent?->starts_at?->utc()->toIso8601String())->toBe('2026-08-01T02:00:00+00:00')
        ->and($mlbGame->sportEvent?->providerMappings()->where('provider', 'espn')->value('provider_uid'))
        ->toBe('s:1~l:10~e:401990001')
        ->and($wnbaGame->sportEvent?->providerMappings()->where('provider', 'espn')->value('provider_uid'))
        ->toBe('s:40~l:59~e:401990002')
        ->and(SportEvent::query()->count())->toBe(2)
        ->and(SportEventProviderMapping::query()->count())->toBe(2);
});

it('dual writes nflverse schedule identities without treating synthetic legacy ids as ESPN or Odds API ids', function () {
    Team::factory()->create(['abbreviation' => 'KC']);
    Team::factory()->create(['abbreviation' => 'DEN']);
    $path = sys_get_temp_dir().'/nflverse-canonical-identity-test.csv';
    File::put($path, implode("\n", [
        'game_id,season,game_type,week,gameday,gametime,away_team,home_team',
        '2026_01_DEN_KC,2026,REG,1,2026-09-13,20:20,DEN,KC',
    ]));

    try {
        artisan('nfl:import-nflverse-schedules', ['file' => $path])->assertSuccessful();
        artisan('nfl:import-nflverse-schedules', ['file' => $path])->assertSuccessful();

        $game = Game::query()
            ->where('nflverse_game_id', '2026_01_DEN_KC')
            ->firstOrFail();

        expect($game->espn_event_id)->toBe('nflverse:2026_01_DEN_KC')
            ->and($game->sportEvent?->sport)->toBe('nfl')
            ->and($game->sportEvent?->providerMappings()->pluck('provider')->all())->toBe(['nflverse'])
            ->and(SportEvent::query()->count())->toBe(1)
            ->and(SportEventProviderMapping::query()->count())->toBe(1);
    } finally {
        File::delete($path);
    }
});
