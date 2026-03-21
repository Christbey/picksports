<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InjuryController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_SPORTS = ['nba', 'wnba', 'nfl', 'cfb', 'cbb', 'wcbb', 'mlb'];

    public function index(Request $request): JsonResponse
    {
        $sport = strtolower((string) $request->route('sport'));
        if (! in_array($sport, self::SUPPORTED_SPORTS, true)) {
            abort(404);
        }

        $injuryTable = "{$sport}_player_injuries";
        $playerTable = "{$sport}_players";
        $teamTable = "{$sport}_teams";

        if (! Schema::hasTable($injuryTable) || ! Schema::hasTable($teamTable) || ! Schema::hasTable($playerTable)) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'sport' => $sport,
                    'total' => 0,
                    'teams' => 0,
                ],
            ]);
        }

        $playerCols = Schema::getColumnListing($playerTable);
        $nameColumn = $this->resolvePlayerNameColumn($playerCols);

        $query = DB::table("{$injuryTable} as i")
            ->join("{$teamTable} as t", 't.id', '=', 'i.team_id')
            ->leftJoin("{$playerTable} as p", 'p.id', '=', 'i.player_id')
            ->select([
                'i.id',
                'i.player_id',
                'i.team_id',
                'i.status',
                'i.detail',
                'i.type',
                'i.injury_date',
                'i.return_date',
                'i.source_updated_at',
                'i.is_active',
                'i.updated_at',
                't.abbreviation as team_abbreviation',
                DB::raw($nameColumn.' as player_name'),
            ]);

        $activeOnly = filter_var($request->query('active', true), FILTER_VALIDATE_BOOL);
        if ($activeOnly) {
            $query->where('i.is_active', true);
        }

        $teamId = $request->integer('team_id');
        if ($teamId > 0) {
            $query->where('i.team_id', $teamId);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $query->where('i.status', $status);
        }

        $rows = $query
            ->orderBy('t.abbreviation')
            ->orderByDesc('i.is_active')
            ->orderByDesc('i.updated_at')
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'sport' => $sport,
                'total' => $rows->count(),
                'teams' => $rows->pluck('team_id')->unique()->count(),
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function resolvePlayerNameColumn(array $columns): string
    {
        foreach (['full_name', 'display_name', 'name', 'short_name'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return "NULLIF(p.{$candidate}, '')";
            }
        }

        if (in_array('first_name', $columns, true) || in_array('last_name', $columns, true)) {
            return "NULLIF(TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))), '')";
        }

        return "'Unknown Player'";
    }
}
