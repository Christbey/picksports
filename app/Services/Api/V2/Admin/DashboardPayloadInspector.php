<?php

namespace App\Services\Api\V2\Admin;

use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardPayloadInspector
{
    public function __construct(
        private readonly SportContextResolver $sports,
    ) {}

    /**
     * @param  array{profile: string, date: string, sports: array<int, string>, include_payload: bool, include_warnings: bool}  $inputs
     * @return array<string, mixed>
     */
    public function inspect(array $inputs): array
    {
        $contexts = collect($inputs['sports'])
            ->map(fn (string $sport): ?SportContext => $this->sports->find($sport))
            ->filter()
            ->values();

        $warnings = $this->warnings($inputs, $contexts->all());
        $data = [
            'profile' => $inputs['profile'],
            'generated_at' => now()->toIso8601String(),
            'inputs' => $inputs,
            'diagnostics' => [
                'requested_sports_count' => count($inputs['sports']),
                'resolved_sports_count' => $contexts->count(),
                'payload_included' => $inputs['include_payload'],
                'warnings_included' => $inputs['include_warnings'],
                'warning_count' => count($warnings),
            ],
        ];

        if ($inputs['include_warnings']) {
            $data['diagnostics']['warnings'] = $warnings;
        }

        if ($inputs['include_payload']) {
            $data['payload'] = [
                'sports' => $contexts
                    ->map(fn (SportContext $context): array => $this->sportPayload($context, $inputs['date']))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'version' => 'v2',
                'contract' => 'admin.payload-inspector',
                'profile' => $inputs['profile'],
                'shell' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sportPayload(SportContext $context, string $date): array
    {
        $games = $this->gameDiagnostics($context, $date);
        $predictions = $this->predictionDiagnostics($context, $date);

        return [
            'slug' => $context->slug,
            'label' => $context->label,
            'namespace' => $context->namespace,
            'capabilities' => $context->capabilities,
            'web' => [
                'pages' => (array) ($context->web['pages'] ?? []),
                'details' => (array) ($context->web['details'] ?? []),
                'player_props' => (bool) ($context->web['player_props'] ?? false),
            ],
            'games' => $games,
            'predictions' => $predictions,
            'dashboard_contract' => $this->dashboardContract($games, $predictions),
            'v2_contracts' => $this->v2Contracts($context),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gameDiagnostics(SportContext $context, string $date): array
    {
        $gameModel = $context->models['game'] ?? null;

        if (! is_string($gameModel) || ! is_subclass_of($gameModel, Model::class)) {
            return [
                'available' => false,
                'for_date' => 0,
                'total' => 0,
                'latest_updated_at' => null,
            ];
        }

        try {
            $table = (new $gameModel)->getTable();

            if (! Schema::hasTable($table)) {
                return [
                    'available' => false,
                    'for_date' => 0,
                    'total' => 0,
                    'latest_updated_at' => null,
                ];
            }

            return [
                'available' => true,
                'for_date' => $this->countForDate($gameModel, $table, $date),
                'total' => $gameModel::query()->count(),
                'latest_updated_at' => $this->latestUpdatedAt($gameModel, $table),
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'for_date' => 0,
                'total' => 0,
                'latest_updated_at' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function predictionDiagnostics(SportContext $context, string $date): array
    {
        $predictionModel = $context->models['prediction'] ?? null;

        if (! is_string($predictionModel) || ! is_subclass_of($predictionModel, Model::class)) {
            return [
                'available' => false,
                'for_date' => 0,
                'total' => 0,
                'latest_updated_at' => null,
            ];
        }

        try {
            $table = (new $predictionModel)->getTable();

            if (! Schema::hasTable($table)) {
                return [
                    'available' => false,
                    'for_date' => 0,
                    'total' => 0,
                    'latest_updated_at' => null,
                ];
            }

            return [
                'available' => true,
                'for_date' => $this->predictionCountForDate($predictionModel, $date),
                'total' => $predictionModel::query()->count(),
                'latest_updated_at' => $this->latestUpdatedAt($predictionModel, $table),
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'for_date' => 0,
                'total' => 0,
                'latest_updated_at' => null,
            ];
        }
    }

    /**
     * @param  class-string<Model>  $predictionModel
     */
    private function predictionCountForDate(string $predictionModel, string $date): int
    {
        if (! method_exists($predictionModel, 'game')) {
            return 0;
        }

        return $predictionModel::query()
            ->whereHas('game', fn ($query) => $query->whereDate('game_date', $date))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $games
     * @param  array<string, mixed>  $predictions
     * @return array<string, mixed>
     */
    private function dashboardContract(array $games, array $predictions): array
    {
        $warnings = [];

        if (($games['available'] ?? false) !== true) {
            $warnings[] = [
                'code' => 'dashboard_games_unavailable',
                'message' => 'Dashboard game payload cannot be validated because the game model/table is unavailable.',
            ];
        }

        if (($predictions['available'] ?? false) !== true) {
            $warnings[] = [
                'code' => 'dashboard_predictions_unavailable',
                'message' => 'Dashboard prediction payload cannot be validated because the prediction model/table is unavailable.',
            ];
        }

        if (($games['for_date'] ?? 0) > 0 && ($predictions['for_date'] ?? 0) === 0) {
            $warnings[] = [
                'code' => 'dashboard_prediction_gap',
                'message' => 'Games exist for the selected date but no matching predictions were found.',
            ];
        }

        return [
            'profile' => 'dashboard',
            'status' => $warnings === [] ? 'passing' : 'warning',
            'vue_contract' => [
                'sport_fields' => ['name', 'fullName', 'color', 'predictions'],
                'stats_fields' => ['total_predictions_today', 'total_games_today', 'healthcheck_status'],
                'prediction_fields' => [
                    'id',
                    'sport',
                    'game_id',
                    'game',
                    'game_time',
                    'home_team',
                    'away_team',
                    'win_probability',
                    'predicted_spread',
                    'predicted_total',
                    'status',
                ],
            ],
            'source_counts' => [
                'games_for_date' => (int) ($games['for_date'] ?? 0),
                'predictions_for_date' => (int) ($predictions['for_date'] ?? 0),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function v2Contracts(SportContext $context): array
    {
        return [
            'games' => $this->contract('games', "/api/v2/sports/{$context->slug}/games", isset($context->models['game'])),
            'teams' => $this->contract('teams', "/api/v2/sports/{$context->slug}/teams", isset($context->models['team'])),
            'players' => $this->contract('players', "/api/v2/sports/{$context->slug}/players", isset($context->models['player'])),
            'predictions' => $this->contract('predictions', "/api/v2/sports/{$context->slug}/predictions", isset($context->models['prediction'])),
            'player_stats' => $this->contract('player_stats', "/api/v2/sports/{$context->slug}/stats/player", isset($context->models['player_stat'])),
            'team_stats' => $this->contract('team_stats', "/api/v2/sports/{$context->slug}/stats/team", isset($context->models['team_stat'])),
            'player_props' => $this->contract('player_props', "/api/v2/sports/{$context->slug}/markets/player-props", isset($context->models['player_prop'])),
            'futures' => $this->contract('futures', "/api/v2/sports/{$context->slug}/markets/futures", in_array($context->slug, ['nba', 'nfl', 'mlb', 'cbb', 'wcbb'], true)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contract(string $name, string $route, bool $available): array
    {
        return [
            'name' => $name,
            'route' => $route,
            'available' => $available,
            'envelope' => ['data', 'meta'],
            'meta_fields' => ['version', 'sport', 'filters', 'pagination', 'freshness', 'warnings'],
        ];
    }

    /**
     * @param  class-string<Model>  $gameModel
     */
    private function countForDate(string $gameModel, string $table, string $date): int
    {
        if (! Schema::hasColumn($table, 'game_date')) {
            return 0;
        }

        return $gameModel::query()
            ->whereDate('game_date', $date)
            ->count();
    }

    /**
     * @param  class-string<Model>  $gameModel
     */
    private function latestUpdatedAt(string $gameModel, string $table): ?string
    {
        if (! Schema::hasColumn($table, 'updated_at')) {
            return null;
        }

        $updatedAt = $gameModel::query()->max('updated_at');

        return $updatedAt ? CarbonImmutable::parse($updatedAt)->toIso8601String() : null;
    }

    /**
     * @param  array{profile: string, date: string, sports: array<int, string>, include_payload: bool, include_warnings: bool}  $inputs
     * @param  array<int, SportContext>  $contexts
     * @return array<int, array<string, string>>
     */
    private function warnings(array $inputs, array $contexts): array
    {
        $resolved = collect($contexts)->map(fn (SportContext $context): string => $context->slug)->all();
        $missing = array_values(array_diff($inputs['sports'], $resolved));

        if ($missing === []) {
            return [];
        }

        return collect($missing)
            ->map(fn (string $sport): array => [
                'code' => 'sport_unresolved',
                'message' => "Sport [{$sport}] could not be resolved for payload inspection.",
            ])
            ->values()
            ->all();
    }
}
