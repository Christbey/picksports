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
use App\Services\Settings\OddsApiTeamMappingIndexDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OddsApiTeamMappingController extends Controller
{
    private const DEFAULT_SPORT = 'basketball_ncaab';

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

    public function __construct(private readonly OddsApiTeamMappingIndexDataService $dataService) {}

    public function index(Request $request): Response
    {
        $sport = $request->get('sport', self::DEFAULT_SPORT);
        $filter = $request->get('filter', 'all'); // all, mapped, unmapped

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
            'stats' => $stats,
            'sports' => $sports,
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
}
