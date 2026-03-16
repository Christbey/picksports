<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CFB\Team as CfbTeam;
use App\Models\CfbdTeamMapping;
use App\Services\Settings\CfbdTeamMappingIndexDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CfbdTeamMappingController extends Controller
{
    private const SPORT = 'americanfootball_ncaaf';

    protected array $config = [
        'label' => 'CFB',
        'teamModel' => CfbTeam::class,
        'teamField' => 'school',
    ];

    public function __construct(private readonly CfbdTeamMappingIndexDataService $dataService) {}

    public function index(Request $request): Response
    {
        $filter = $request->get('filter', 'all');

        return Inertia::render('settings/TeamMappings', [
            'mappings' => $this->dataService->mappings(self::SPORT, $filter),
            'espnTeams' => $this->dataService->espnTeams($this->config),
            'currentSport' => self::SPORT,
            'currentFilter' => $filter,
            'currentProvider' => 'cfbd',
            'providers' => [
                ['key' => 'odds', 'label' => 'Odds API'],
                ['key' => 'cfbd', 'label' => 'CFBD'],
            ],
            'queryParams' => ['provider' => 'cfbd'],
            'entityType' => 'team',
            'stats' => $this->dataService->stats(self::SPORT),
            'sports' => [['key' => self::SPORT, 'label' => 'CFB']],
            'indexBase' => '/settings/team-mappings',
            'mutationBase' => '/settings/cfbd-team-mappings',
            'pageTitle' => 'CFBD Team Mappings',
            'pageDescription' => 'Resolve CollegeFootballData team names to your internal CFB teams.',
            'externalSourceLabel' => 'CFBD',
            'emptyStateCommand' => 'php artisan cfbd:populate-team-mappings',
        ]);
    }

    public function update(Request $request, CfbdTeamMapping $mapping)
    {
        $validated = $request->validate([
            'espn_team_name' => 'nullable|string|max:255',
        ]);

        $mapping->update($validated);

        return back();
    }

    public function destroy(CfbdTeamMapping $mapping)
    {
        $mapping->update(['espn_team_name' => null]);

        return back();
    }
}
