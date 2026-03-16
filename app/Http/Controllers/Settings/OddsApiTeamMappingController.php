<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Team as CfbTeam;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Team as NflTeam;
use App\Models\OddsApiTeamMapping;
use App\Models\WCBB\Team as WcbbTeam;
use App\Models\WNBA\Team as WnbaTeam;
use App\Services\Settings\CfbdTeamMappingIndexDataService;
use App\Services\Settings\OddsApiTeamMappingIndexDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class OddsApiTeamMappingController extends Controller
{
    private const DEFAULT_SPORT = 'basketball_ncaab';

    private const DEFAULT_PROVIDER = 'odds';

    protected array $sportConfigs = [
        'basketball_ncaab' => [
            'label' => 'CBB',
            'teamModel' => CbbTeam::class,
            'teamField' => 'school',
        ],
        'basketball_wncaab' => [
            'label' => 'WCBB',
            'teamModel' => WcbbTeam::class,
            'teamField' => 'school',
        ],
        'basketball_nba' => [
            'label' => 'NBA',
            'teamModel' => NbaTeam::class,
            'teamField' => 'location',
        ],
        'basketball_wnba' => [
            'label' => 'WNBA',
            'teamModel' => WnbaTeam::class,
            'teamField' => 'location',
        ],
        'baseball_mlb' => [
            'label' => 'MLB',
            'teamModel' => MlbTeam::class,
            'teamField' => 'location',
        ],
        'americanfootball_nfl' => [
            'label' => 'NFL',
            'teamModel' => NflTeam::class,
            'teamField' => 'location',
        ],
        'americanfootball_ncaaf' => [
            'label' => 'CFB',
            'teamModel' => CfbTeam::class,
            'teamField' => 'school',
        ],
    ];

    public function __construct(
        private readonly OddsApiTeamMappingIndexDataService $dataService,
        private readonly CfbdTeamMappingIndexDataService $cfbdDataService,
    ) {}

    public function index(Request $request): Response
    {
        $provider = $request->get('provider', self::DEFAULT_PROVIDER);
        $sport = $request->get('sport', self::DEFAULT_SPORT);
        $filter = $request->get('filter', 'all'); // all, mapped, unmapped

        if ($provider === 'cfbd') {
            $sport = 'americanfootball_ncaaf';
            $config = $this->sportConfigs[$sport];

            return Inertia::render('settings/TeamMappings', [
                'mappings' => $this->cfbdDataService->mappings($sport, $filter),
                'espnTeams' => $this->cfbdDataService->espnTeams($config),
                'currentSport' => $sport,
                'currentFilter' => $filter,
                'currentProvider' => 'cfbd',
                'providers' => [
                    ['key' => 'odds', 'label' => 'Odds API'],
                    ['key' => 'cfbd', 'label' => 'CFBD'],
                ],
                'queryParams' => ['provider' => 'cfbd'],
                'entityType' => 'team',
                'stats' => $this->cfbdDataService->stats($sport),
                'sports' => [['key' => $sport, 'label' => 'CFB']],
                'indexBase' => '/settings/team-mappings',
                'mutationBase' => '/settings/cfbd-team-mappings',
                'pageTitle' => 'CFBD Team Mappings',
                'pageDescription' => 'Resolve CollegeFootballData team names to your internal CFB teams.',
                'externalSourceLabel' => 'CFBD',
                'emptyStateCommand' => 'php artisan cfbd:populate-team-mappings',
            ]);
        }

        $sport = $this->dataService->normalizeSport($sport, $this->sportConfigs, self::DEFAULT_SPORT);

        $config = $this->sportConfigs[$sport];
        $stats = $this->dataService->stats($sport);
        $mappings = $this->dataService->mappings($sport, $filter);
        $espnTeams = $this->dataService->espnTeams($config);
        $sports = $this->dataService->sports($this->sportConfigs);

        return Inertia::render('settings/TeamMappings', [
            'mappings' => $mappings,
            'espnTeams' => $espnTeams,
            'currentSport' => $sport,
            'currentFilter' => $filter,
            'currentProvider' => 'odds',
            'providers' => [
                ['key' => 'odds', 'label' => 'Odds API'],
                ['key' => 'cfbd', 'label' => 'CFBD'],
            ],
            'queryParams' => ['provider' => 'odds'],
            'entityType' => 'team',
            'stats' => $stats,
            'sports' => $sports,
            'indexBase' => '/settings/team-mappings',
            'mutationBase' => '/settings/team-mappings',
        ]);
    }

    public function update(Request $request, OddsApiTeamMapping $mapping)
    {
        $validated = $request->validate([
            'espn_team_name' => 'nullable|string|max:255',
        ]);

        $mapping->update($validated);

        return back();
    }

    public function destroy(OddsApiTeamMapping $mapping)
    {
        $mapping->update(['espn_team_name' => null]);

        return back();
    }

    public function sync(Request $request)
    {
        $provider = $request->input('provider', self::DEFAULT_PROVIDER);

        if ($provider !== 'odds') {
            return $this->backError('Sync is only available for the Odds API provider on this screen.');
        }

        try {
            $exitCode = Artisan::call('odds:populate-team-mappings', [
                '--all' => true,
            ]);

            if ($exitCode !== 0) {
                return $this->backWarning('Odds API team sync finished with warnings. Review the command output in logs if mappings look incomplete.');
            }

            return $this->backSuccess('Odds API team mappings synced successfully for all supported sports.');
        } catch (\Throwable $e) {
            return $this->backError('Failed to sync Odds API team mappings: '.$e->getMessage());
        }
    }
}
