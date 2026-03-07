<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\OddsApiPlayerMapping;
use App\Services\Settings\OddsApiPlayerMappingIndexDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OddsApiPlayerMappingController extends Controller
{
    private const DEFAULT_SPORT = 'basketball_nba';

    protected array $sportConfigs = [
        'basketball_nba' => [
            'label' => 'NBA',
        ],
        'basketball_ncaab' => [
            'label' => 'CBB',
        ],
        'americanfootball_nfl' => [
            'label' => 'NFL',
        ],
        'baseball_mlb' => [
            'label' => 'MLB',
        ],
    ];

    public function __construct(private readonly OddsApiPlayerMappingIndexDataService $dataService) {}

    public function index(Request $request): Response
    {
        $sport = $request->get('sport', self::DEFAULT_SPORT);
        $filter = $request->get('filter', 'all');

        $sport = $this->dataService->normalizeSport($sport, $this->sportConfigs, self::DEFAULT_SPORT);

        return Inertia::render('settings/TeamMappings', [
            'mappings' => $this->dataService->mappings($sport, $filter),
            'espnTeams' => [],
            'currentSport' => $sport,
            'currentFilter' => $filter,
            'entityType' => 'player',
            'stats' => $this->dataService->stats($sport),
            'sports' => $this->dataService->sports($this->sportConfigs),
        ]);
    }

    public function update(Request $request, OddsApiPlayerMapping $mapping)
    {
        $validated = $request->validate([
            'espn_player_name' => 'nullable|string|max:255',
        ]);

        $mapping->update($validated);

        return back();
    }

    public function destroy(OddsApiPlayerMapping $mapping)
    {
        $mapping->update(['espn_player_name' => null]);

        return back();
    }
}
