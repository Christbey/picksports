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
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OddsApiTeamMappingController extends Controller
{
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
            'teamField' => 'display_name',
        ],
        'baseball_mlb' => [
            'label' => 'MLB',
            'teamModel' => MlbTeam::class,
            'teamField' => 'location',
        ],
        'americanfootball_nfl' => [
            'label' => 'NFL',
            'teamModel' => NflTeam::class,
            'teamField' => 'display_name',
        ],
        'americanfootball_ncaaf' => [
            'label' => 'CFB',
            'teamModel' => CfbTeam::class,
            'teamField' => 'display_name',
        ],
    ];

    public function index(Request $request): Response
    {
        $sport = $request->get('sport', 'basketball_ncaab');
        $filter = $request->get('filter', 'all'); // all, mapped, unmapped

        if (! isset($this->sportConfigs[$sport])) {
            $sport = 'basketball_ncaab';
        }

        $config = $this->sportConfigs[$sport];

        // Get statistics
        $stats = [
            'total' => OddsApiTeamMapping::where('sport', $sport)->count(),
            'mapped' => OddsApiTeamMapping::where('sport', $sport)->whereNotNull('espn_team_name')->count(),
            'unmapped' => OddsApiTeamMapping::where('sport', $sport)->whereNull('espn_team_name')->count(),
        ];

        // Build query with filter
        $query = OddsApiTeamMapping::query()->where('sport', $sport);

        if ($filter === 'mapped') {
            $query->whereNotNull('espn_team_name');
        } elseif ($filter === 'unmapped') {
            $query->whereNull('espn_team_name');
        }

        $mappings = $query
            ->orderByRaw('espn_team_name IS NULL DESC')
            ->orderBy('odds_api_team_name')
            ->paginate(50)
            ->appends(['sport' => $sport, 'filter' => $filter]);

        $teamModel = $config['teamModel'];
        $teamField = $config['teamField'];

        $espnTeams = $teamModel::query()
            ->select('id', $teamField.' as name', 'abbreviation')
            ->orderBy($teamField)
            ->get();

        $sports = collect($this->sportConfigs)->map(fn ($c, $key) => [
            'key' => $key,
            'label' => $c['label'],
        ])->values();

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
