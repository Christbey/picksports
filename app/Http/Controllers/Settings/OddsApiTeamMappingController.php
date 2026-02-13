<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CBB\Team as CbbTeam;
use App\Models\CFB\Team as CfbTeam;
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
        'basketball_wnba' => [
            'label' => 'WNBA',
            'teamModel' => WnbaTeam::class,
            'teamField' => 'display_name',
        ],
    ];

    public function index(Request $request): Response
    {
        $sport = $request->get('sport', 'basketball_ncaab');

        if (! isset($this->sportConfigs[$sport])) {
            $sport = 'basketball_ncaab';
        }

        $config = $this->sportConfigs[$sport];

        $mappings = OddsApiTeamMapping::query()
            ->where('sport', $sport)
            ->orderByRaw('espn_team_name IS NULL DESC')
            ->orderBy('odds_api_team_name')
            ->paginate(50);

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
