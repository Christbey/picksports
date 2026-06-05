<?php

namespace App\Services\Api\V2\Admin;

use App\Models\CbbBracket;
use App\Models\Group;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserBet;
use App\Models\WebPushSubscription;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Settings\FoundingUsersSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;

class DashboardPayloadInspector
{
    public function __construct(
        private readonly SportContextResolver $sports,
        private readonly FoundingUsersSettingsService $foundingUsersSettings,
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
            $data['payload'] = $this->payloadForProfile($inputs, $contexts->all());
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
     * @param  array{profile: string, date: string, sports: array<int, string>, include_payload: bool, include_warnings: bool}  $inputs
     * @param  array<int, SportContext>  $contexts
     * @return array<string, mixed>
     */
    private function payloadForProfile(array $inputs, array $contexts): array
    {
        return match ($inputs['profile']) {
            'user-bets' => [
                'app_surface' => $this->userBetsPayload(),
            ],
            'cbb-brackets' => [
                'app_surface' => $this->cbbBracketsPayload(),
            ],
            'settings-admin' => [
                'app_surface' => $this->settingsAdminPayload(),
            ],
            'alert-preferences' => [
                'app_surface' => $this->alertPreferencesPayload(),
            ],
            default => [
                'sports' => collect($contexts)
                    ->map(fn (SportContext $context): array => $this->sportPayload($context, $inputs['date']))
                    ->values()
                    ->all(),
            ],
        };
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
    private function userBetsPayload(): array
    {
        $diagnostics = $this->modelDiagnostics(UserBet::class);

        return [
            'profile' => 'user-bets',
            'source_endpoint' => '/api/v2/user-bets',
            'vue_consumers' => [
                'resources/js/pages/MyBets.vue',
                'resources/js/components/predictions/UnifiedPredictionCard.vue',
                'resources/js/components/predictions/SavePickDialog.vue',
            ],
            'contract_shape' => [
                'index' => ['bets', 'statistics', 'tracking'],
                'resource' => ['data'],
            ],
            'critical_fields' => [
                'id',
                'bet_amount',
                'odds',
                'bet_type',
                'selection_side',
                'selection_label',
                'result',
                'profit_loss',
                'placed_at',
            ],
            'diagnostics' => $diagnostics + [
                'pending_count' => $this->safeCount(UserBet::class, fn ($query) => $query->where('result', 'pending')),
                'tracked_prediction_count' => $this->safeCount(UserBet::class, fn ($query) => $query->whereNotNull('prediction_id')),
            ],
            'warnings' => $this->missingTableWarnings('user-bets', $diagnostics),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cbbBracketsPayload(): array
    {
        $brackets = $this->modelDiagnostics(CbbBracket::class);
        $groups = $this->modelDiagnostics(Group::class);

        return [
            'profile' => 'cbb-brackets',
            'source_endpoints' => [
                '/api/v2/cbb-brackets',
                '/api/v2/cbb-brackets/leaderboard',
                '/api/v2/groups',
            ],
            'vue_consumers' => [
                'resources/js/pages/MarchMadnessBracket.vue',
            ],
            'contract_shape' => [
                'brackets' => ['data'],
                'leaderboard' => ['data'],
                'groups' => ['data'],
            ],
            'critical_fields' => [
                'public_id',
                'season',
                'name',
                'group_id',
                'picks',
                'points_earned',
                'max_points_remaining',
                'correct_picks',
                'incorrect_picks',
                'results',
                'is_locked',
                'can_edit',
            ],
            'diagnostics' => [
                'brackets' => $brackets + [
                    'submitted_count' => $this->safeCount(CbbBracket::class, fn ($query) => $query->whereNotNull('submitted_at')),
                    'grouped_count' => $this->safeCount(CbbBracket::class, fn ($query) => $query->whereNotNull('group_id')),
                ],
                'groups' => $groups + [
                    'bracket_pool_count' => $this->safeCount(Group::class, fn ($query) => $query->where('type', 'bracket_pool')->where('sport', 'cbb')),
                ],
            ],
            'warnings' => [
                ...$this->missingTableWarnings('cbb-brackets', $brackets),
                ...$this->missingTableWarnings('groups', $groups),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsAdminPayload(): array
    {
        $groups = $this->modelDiagnostics(Group::class);
        $users = $this->modelDiagnostics(User::class);
        $roleName = (string) config('founding_users.role', 'founding_user');
        $guardName = (string) config('auth.defaults.guard', 'web');
        $roleExists = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', $guardName)
            ->exists();

        return [
            'profile' => 'settings-admin',
            'source_endpoints' => [
                '/settings/admin',
                '/settings/admin/founding-users/search',
                '/settings/admin/groups/users/search',
            ],
            'vue_consumers' => [
                'resources/js/pages/settings/Admin.vue',
            ],
            'contract_shape' => [
                'inertia_props' => ['foundingUsers', 'groups'],
                'search_payload' => ['users'],
            ],
            'critical_fields' => [
                'foundingUsers.enabled',
                'foundingUsers.limit',
                'foundingUsers.used',
                'foundingUsers.remaining',
                'groups.id',
                'groups.public_id',
                'groups.name',
                'groups.members',
            ],
            'diagnostics' => [
                'users' => $users,
                'groups' => $groups + [
                    'admin_visible_bracket_pool_count' => $this->safeCount(Group::class, fn ($query) => $query->where('type', 'bracket_pool')->where('sport', 'cbb')),
                ],
                'founding_users' => [
                    'enabled' => (bool) config('founding_users.enabled', false),
                    'limit' => $this->foundingUsersSettings->getLimit(),
                    'role' => $roleName,
                    'role_exists' => $roleExists,
                    'tier_slug' => (string) config('founding_users.tier_slug', 'premium'),
                ],
            ],
            'warnings' => [
                ...$this->missingTableWarnings('settings-admin-users', $users),
                ...$this->missingTableWarnings('settings-admin-groups', $groups),
                ...($roleExists ? [] : [[
                    'code' => 'founding_role_missing',
                    'message' => 'The configured founding user role does not exist.',
                ]]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function alertPreferencesPayload(): array
    {
        $preferences = $this->modelDiagnostics(UserAlertPreference::class);
        $pushSubscriptions = $this->modelDiagnostics(WebPushSubscription::class);

        return [
            'profile' => 'alert-preferences',
            'source_endpoints' => [
                '/settings/alert-preferences',
                '/settings/web-push/subscriptions',
                '/settings/web-push/test',
            ],
            'vue_consumers' => [
                'resources/js/pages/settings/AlertPreferences.vue',
            ],
            'contract_shape' => [
                'inertia_props' => ['preference', 'webPush'],
                'web_push_response' => ['ok', 'message'],
            ],
            'critical_fields' => [
                'preference.enabled',
                'preference.sports',
                'preference.notification_types',
                'preference.minimum_edge',
                'preference.digest_mode',
                'preference.daily_digest_subscribed',
                'webPush.configured',
                'webPush.publicKey',
                'webPush.hasSubscription',
            ],
            'diagnostics' => [
                'preferences' => $preferences + [
                    'enabled_count' => $this->safeCount(UserAlertPreference::class, fn ($query) => $query->where('enabled', true)),
                    'daily_digest_subscribed_count' => $this->safeCount(UserAlertPreference::class, fn ($query) => $query->where('daily_digest_subscribed', true)),
                ],
                'web_push_subscriptions' => $pushSubscriptions + [
                    'active_count' => $this->safeCount(WebPushSubscription::class, fn ($query) => $query->whereNull('expired_at')),
                ],
                'web_push_configured' => filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key')),
            ],
            'warnings' => [
                ...$this->missingTableWarnings('alert-preferences', $preferences),
                ...$this->missingTableWarnings('web-push-subscriptions', $pushSubscriptions),
            ],
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
     * @param  class-string<Model>  $modelClass
     * @return array<string, mixed>
     */
    private function modelDiagnostics(string $modelClass): array
    {
        try {
            $table = (new $modelClass)->getTable();

            if (! Schema::hasTable($table)) {
                return [
                    'available' => false,
                    'table' => $table,
                    'total' => 0,
                    'latest_updated_at' => null,
                ];
            }

            return [
                'available' => true,
                'table' => $table,
                'total' => $modelClass::query()->count(),
                'latest_updated_at' => $this->latestUpdatedAt($modelClass, $table),
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'table' => null,
                'total' => 0,
                'latest_updated_at' => null,
            ];
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function safeCount(string $modelClass, callable $callback): int
    {
        try {
            $table = (new $modelClass)->getTable();

            if (! Schema::hasTable($table)) {
                return 0;
            }

            $query = $modelClass::query();
            $callback($query);

            return $query->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array<int, array<string, string>>
     */
    private function missingTableWarnings(string $profile, array $diagnostics): array
    {
        if (($diagnostics['available'] ?? false) === true) {
            return [];
        }

        return [[
            'code' => "{$profile}_table_unavailable",
            'message' => "The {$profile} payload cannot be fully validated because its backing table is unavailable.",
        ]];
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
