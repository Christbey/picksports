<?php

namespace App\Console\Commands\Api;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class V2OpenApiGenerateCommand extends Command
{
    protected $signature = 'api:v2-openapi-generate
        {--output=docs/openapi-v2.json : Output path, relative to the project root unless absolute}
        {--server-url=https://picksports.app : Server URL to include in the generated spec}
        {--stdout : Print the generated OpenAPI JSON instead of writing a file}';

    protected $description = 'Generate an OpenAPI 3.1 JSON artifact from the registered API v2 routes.';

    /**
     * @var array<string, array<int, string>>
     */
    private array $queryParametersByFamily = [
        'games' => ['status', 'completed_only', 'season', 'season_type', 'from_date', 'to_date', 'before_game_at', 'exclude_game_id', 'per_page'],
        'predictions' => ['season', 'season_type', 'week', 'from_date', 'to_date', 'status', 'team_id', 'game_id', 'include', 'market', 'per_page'],
        'teams' => ['conference', 'division', 'league', 'search', 'per_page'],
        'players' => ['team_id', 'position', 'status', 'search', 'per_page'],
        'metrics.teams' => ['season', 'season_type', 'team_id', 'per_page'],
        'stats' => ['season', 'season_type', 'week', 'from_date', 'to_date', 'game_id', 'team_id', 'player_id', 'stat_type', 'team_type', 'per_page'],
        'season-averages' => ['season', 'season_type'],
        'trends' => ['games', 'season', 'season_type', 'before_date'],
        'player-props' => ['search', 'page', 'date', 'from_date', 'to_date', 'game_id', 'player_id', 'market', 'bookmaker', 'recommended_side', 'only_ungraded', 'per_page'],
        'futures' => ['season', 'market_key', 'bookmaker', 'team_id', 'player_id', 'event_id', 'outcome_name', 'per_page'],
        'forecasts' => ['season', 'as_of_date'],
        'signals' => ['season', 'as_of_date'],
        'injuries' => ['active', 'actionable', 'team_id', 'status', 'limit'],
        'leaderboards.players' => ['season', 'season_type', 'stat_type', 'min_games'],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $queryParameterSchemas = [
        'since' => ['type' => 'integer', 'minimum' => 2009, 'description' => 'No later than the selected game season.'],
        'home_line' => ['type' => 'number', 'minimum' => -60, 'maximum' => 60, 'multipleOf' => 0.5],
        'window' => ['type' => 'string', 'enum' => ['season_to_date', 'previous_season']],
        'active' => ['type' => 'boolean'],
        'filters_only' => ['type' => 'boolean', 'default' => false],
        'game' => ['type' => 'integer'],
        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 150],
        'as_of_date' => ['type' => 'string', 'format' => 'date'],
        'before_date' => ['type' => 'string', 'format' => 'date'],
        'before_game_at' => ['type' => 'string', 'description' => 'Exclusive kickoff cutoff. Use an offset-bearing ISO 8601 timestamp for an unambiguous instant.'],
        'completed_only' => ['type' => 'boolean', 'description' => 'Restrict to stored final or full-time games before pagination.'],
        'exclude_game_id' => ['type' => 'integer', 'minimum' => 1],
        'bookmaker' => ['type' => 'string'],
        'conference' => ['type' => 'string'],
        'date' => ['type' => 'string', 'format' => 'date'],
        'division' => ['type' => 'string'],
        'event_id' => ['type' => 'string'],
        'from_date' => ['type' => 'string', 'format' => 'date'],
        'game_id' => ['type' => 'integer'],
        'games' => ['type' => 'integer', 'minimum' => 1],
        'include' => ['type' => 'string'],
        'league' => ['type' => 'string'],
        'market' => ['type' => 'string'],
        'market_key' => ['type' => 'string'],
        'min_games' => ['type' => 'integer', 'minimum' => 0],
        'outcome_name' => ['type' => 'string'],
        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        'player_id' => ['type' => 'integer'],
        'position' => ['type' => 'string'],
        'recommended_side' => ['type' => 'string'],
        'search' => ['type' => 'string'],
        'page' => ['type' => 'integer', 'minimum' => 1],
        'season' => ['type' => 'integer'],
        'season_type' => ['type' => 'integer'],
        'stat_type' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'team_id' => ['type' => 'integer'],
        'team_type' => ['type' => 'string'],
        'to_date' => ['type' => 'string', 'format' => 'date'],
        'week' => ['type' => 'integer'],
    ];

    public function handle(): int
    {
        $json = json_encode($this->spec(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

        if ((bool) $this->option('stdout')) {
            $this->output->write($json);

            return self::SUCCESS;
        }

        $path = $this->outputPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json);

        $this->info(sprintf('Generated API v2 OpenAPI spec at %s.', $path));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $paths = [];

        foreach ($this->v2Routes() as $route) {
            $path = '/'.$route->uri();

            foreach ($this->routeMethods($route) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'PickSports API v2',
                'version' => '2.0.0',
                'description' => 'Generated OpenAPI artifact for the PickSports Laravel API v2 surface. Every operation uses a named request and response contract; sport-dependent aggregate fields remain explicitly extensible where their shape varies by provider or league.',
            ],
            'servers' => [
                ['url' => rtrim((string) $this->option('server-url'), '/')],
            ],
            'tags' => $this->tags(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'sanctumBearer' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum or OAuth 2 access token',
                    ],
                    'nativeOAuth' => [
                        'type' => 'oauth2',
                        'flows' => [
                            'authorizationCode' => [
                                'authorizationUrl' => '/oauth/authorize',
                                'tokenUrl' => '/oauth/token',
                                'scopes' => [
                                    'mobile:read' => 'Read account-authorized PickSports data.',
                                    'mobile:write' => 'Manage first-party mobile account data.',
                                ],
                            ],
                        ],
                    ],
                    'developerApiCredential' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'PickSports developer API credential',
                    ],
                ],
                'responses' => $this->sharedResponses(),
                'schemas' => $this->schemas(),
            ],
        ];
    }

    /**
     * @return array<int, Route>
     */
    private function v2Routes(): array
    {
        return collect(RouteFacade::getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v2'))
            ->sortBy(fn (Route $route): string => $route->uri().'|'.implode(',', $this->routeMethods($route)))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function routeMethods(Route $route): array
    {
        return collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method): array
    {
        $name = $route->getName() ?: Str::of($route->uri())->replace(['/', '{', '}'], ['.', '', ''])->toString();
        $operation = [
            'tags' => [$this->tagForRoute($route)],
            'operationId' => Str::of($name)->replace(['.', '-'], '_')->toString(),
            'summary' => $this->summary($name, $method),
            'parameters' => array_values(array_merge(
                $this->pathParameters($route),
                $this->queryParameters($name)
            )),
            'responses' => $this->responsesFor($route),
        ];

        if ($this->requiresSanctum($route)) {
            $operation['security'] = [
                ['sanctumBearer' => []],
                ['nativeOAuth' => [$this->oauthScope($method)]],
            ];
        }

        if ($this->requiresDeveloperCredential($route)) {
            $operation['security'] = [['developerApiCredential' => []]];
        }

        if ($this->supportsIdempotency($route)) {
            $operation['parameters'][] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => false,
                'description' => 'Client-generated retry key, scoped to the authenticated principal and operation for 24 hours by default.',
                'schema' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'pattern' => '^[!-~]{1,255}$',
                ],
            ];
        }

        $requestBodyRequired = $this->requestBodyRequired($route);

        if ($requestBodyRequired !== null) {
            $operation['requestBody'] = [
                'required' => $requestBodyRequired,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => $this->requestSchemaReference($route),
                        ],
                    ],
                ],
            ];
        }

        return $operation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pathParameters(Route $route): array
    {
        return collect($route->parameterNames())
            ->map(function (string $parameter) use ($route): array {
                $definition = [
                    'name' => $parameter,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $this->pathParameterSchema($route, $parameter),
                ];
                $description = $this->pathParameterDescription($route, $parameter);

                return $description === null
                    ? $definition
                    : ['description' => $description, ...$definition];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function pathParameterSchema(Route $route, string $parameter): array
    {
        if ($parameter === 'sport') {
            return [
                'type' => 'string',
                'enum' => match ($route->getName()) {
                    'v2.sports.games.live-betting.show' => ['cfb'],
                    'v2.sports.games.live-snapshot.show',
                    'v2.sports.games.research.show',
                    'v2.sports.games.market-history.show',
                    'v2.sports.games.matchup-signals.show' => ['nfl'],
                    default => ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb'],
                },
            ];
        }

        if (($parameter === 'game' && $route->getName() === 'v2.sports.games.player-props.index')
            || ($parameter === 'player' && $route->getName() === 'v2.sports.players.player-props.index')) {
            return ['type' => 'integer', 'minimum' => 1];
        }

        if ($parameter === 'game' && $route->getName() === 'v2.sports.games.live-betting.show') {
            return ['type' => 'integer', 'minimum' => 1];
        }

        if ($parameter === 'game' && $this->supportsCanonicalGameLookup($route)) {
            return [
                'oneOf' => [
                    ['type' => 'integer', 'minimum' => 1],
                    [
                        'type' => 'string',
                        'minLength' => 26,
                        'maxLength' => 26,
                        'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$',
                    ],
                ],
            ];
        }

        if ($parameter === 'deviceSession') {
            return [
                'type' => 'string',
                'minLength' => 26,
                'maxLength' => 26,
                'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$',
            ];
        }

        if ($parameter === 'provider' && str_contains($route->getName() ?? '', 'push-registrations')) {
            return ['type' => 'string', 'enum' => ['apns', 'fcm']];
        }

        if (in_array($parameter, ['bet', 'game', 'team', 'player', 'prediction'], true)) {
            return [
                'oneOf' => [
                    ['type' => 'integer'],
                    ['type' => 'string'],
                ],
            ];
        }

        return ['type' => 'string'];
    }

    private function pathParameterDescription(Route $route, string $parameter): ?string
    {
        if ($parameter === 'game' && $this->supportsCanonicalGameLookup($route)) {
            return 'Legacy numeric game ID or canonical sport-event ULID. A canonical ID must belong to the sport in the URL.';
        }

        return null;
    }

    private function supportsCanonicalGameLookup(Route $route): bool
    {
        return in_array($route->getName(), [
            'v2.sports.games.show',
            'v2.sports.games.page.show',
            'v2.sports.games.trends.show',
            'v2.sports.games.live-snapshot.show',
            'v2.sports.games.research.show',
            'v2.sports.games.analysis.research.show',
            'v2.admin.game-analysis.retry',
            'v2.sports.games.market-history.show',
            'v2.sports.games.matchup-signals.show',
        ], true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryParameters(string $routeName): array
    {
        return collect($this->queryParameterNames($routeName))
            ->map(fn (string $parameter): array => [
                'name' => $parameter,
                'in' => 'query',
                'required' => false,
                'schema' => $this->queryParameterSchema($routeName, $parameter),
                ...($parameter === 'season' && in_array($routeName, ['v2.sports.players.index', 'v2.sports.teams.players.index'], true) ? ['description' => 'CBB team-roster ending-year season; defaults to the current basketball season. Requires team_id on the player directory endpoint.'] : []),
            ])
            ->values()
            ->all();
    }

    private function queryParameterSchema(string $routeName, string $parameter): array
    {
        if (in_array($routeName, ['v2.sports.predictions.index', 'v2.sports.predictions.available-dates'], true)) {
            $specific = match ($parameter) {
                'season_type' => ['type' => 'string', 'maxLength' => 50],
                'team_id', 'game_id' => ['type' => 'integer', 'minimum' => 1],
                'week' => ['type' => 'integer', 'minimum' => 0],
                'view' => ['type' => 'string', 'enum' => ['startup', 'board', 'live'],
                    'description' => 'CFB only. startup excludes explicit season, season_type, week and date filters. Other sports reject this parameter.'],
                'board_version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$',
                    'description' => 'CFB only; accepted only with view=live. Identifies the board snapshot, not a model approval.'],
                'has_value' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Use 0 or 1 for this query-string flag.'],
                default => null,
            };
            if ($specific !== null) {
                return $specific;
            }
        }
        if (in_array($routeName, ['v2.sports.player-props.index', 'v2.sports.markets.player-props.index',
            'v2.sports.games.player-props.index', 'v2.sports.players.player-props.index'], true)) {
            $specific = match ($parameter) {
                'game_id', 'player_id' => ['type' => 'integer', 'minimum' => 1],
                'search', 'market', 'bookmaker' => ['type' => 'string', 'maxLength' => 100],
                'recommended_side' => ['type' => 'string', 'maxLength' => 20],
                'only_ungraded' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Use 0 or 1; CFB additionally restricts to current prop revisions.'],
                default => null,
            };
            if ($specific !== null) {
                return $specific;
            }
        }
        if ($routeName === 'v2.sports.teams.depth-charts.show') {
            return match ($parameter) {
                'season' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999],
                'season_type' => ['type' => 'string', 'description' => 'Configured phase code/key/name or a supported legacy phase name; canonical code is returned. all is not supported.'],
                'before_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Exclusive stored game calendar-date cutoff for completed stat samples, not a historical depth-chart observation instant.'],
                default => ['type' => 'string'],
            };
        }
        if (str_contains($routeName, 'season-averages')) {
            if ($parameter === 'season') {
                return ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999, 'description' => 'Explicit stored completed season. Omitted resolves to the latest stored completed season within the selected phase and requested team.'];
            }
            if ($parameter === 'season_type') {
                return ['type' => 'string', 'description' => 'Configured phase code/key/name for the selected sport, or all. Omitted uses the configured team-metric phase; resolved scope is returned.'];
            }
        }
        if (str_ends_with($routeName, '.injuries.index')) {
            if ($parameter === 'limit') {
                return ['type' => 'integer', 'minimum' => 1, 'maximum' => 500];
            }
            if (in_array($parameter, ['active', 'actionable'], true)) {
                return ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Use 0 or 1 for this query-string flag.'];
            }
        }
        if (in_array($routeName, ['v2.sports.games.index', 'v2.sports.teams.games.index'], true)) {
            if ($parameter === 'per_page') {
                return ['type' => 'integer', 'minimum' => 1, 'maximum' => 500];
            }
            if ($parameter === 'season_type') {
                return ['type' => 'string', 'maxLength' => 50];
            }
        }

        return $this->queryParameterSchemas[$parameter] ?? ['type' => 'string'];
    }

    /**
     * @return array<int, string>
     */
    private function queryParameterNames(string $routeName): array
    {
        if (in_array($routeName, ['v2.sports.predictions.index', 'v2.sports.predictions.available-dates'], true)) {
            return [...$this->queryParametersByFamily['predictions'], 'view', 'board_version', 'has_value'];
        }
        if ($routeName === 'v2.sports.teams.depth-charts.show') {
            return ['season', 'season_type', 'before_date'];
        }
        if ($routeName === 'v2.sports.games.depth-charts.show') {
            return [];
        }
        if (in_array($routeName, ['v2.sports.games.live-betting.show', 'v2.sports.games.live-snapshot.show', 'v2.sports.games.research.show', 'v2.sports.games.analysis.research.show'], true)) {
            return [];
        }
        if ($routeName === 'v2.sports.players.index') {
            return ['season', ...$this->queryParametersByFamily['players']];
        }
        if ($routeName === 'v2.sports.teams.players.index') {
            return ['season', 'position', 'status', 'search', 'per_page'];
        }
        if ($routeName === 'v2.sports.games.market-history.show') {
            return ['since', 'home_line'];
        }
        if ($routeName === 'v2.sports.games.matchup-signals.show') {
            return ['window'];
        }
        if ($routeName === 'v2.sports.player-props.board') {
            return ['date', 'game', 'market', 'limit', 'filters_only'];
        }
        if (str_contains($routeName, 'stats.team.season-averages') || str_contains($routeName, 'teams.stats.season-averages')) {
            return $this->queryParametersByFamily['season-averages'];
        }

        if (str_contains($routeName, 'leaderboards.players')) {
            return $this->queryParametersByFamily['leaderboards.players'];
        }

        if (str_contains($routeName, 'metrics.teams') || str_contains($routeName, 'teams.metrics')) {
            return $this->queryParametersByFamily['metrics.teams'];
        }

        if (str_contains($routeName, 'player-props') || str_contains($routeName, 'markets.player-props')) {
            return $this->queryParametersByFamily['player-props'];
        }

        if (str_contains($routeName, 'futures') || str_contains($routeName, 'markets.futures')) {
            return $this->queryParametersByFamily['futures'];
        }

        if (in_array($routeName, ['v2.sports.games.index', 'v2.sports.teams.games.index'], true)) {
            return $this->queryParametersByFamily['games'];
        }

        foreach ($this->queryParametersByFamily as $family => $parameters) {
            if ($family !== 'games' && str_contains($routeName, '.'.$family.'.')) {
                return $parameters;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function responsesFor(Route $route): array
    {
        $successStatuses = $this->successStatuses($route);
        $responses = collect($successStatuses)
            ->mapWithKeys(function (string $successStatus) use ($route): array {
                $response = [
                    'description' => 'Successful response.',
                    'headers' => $this->requestIdHeader(),
                ];

                if ($successStatus !== '204') {
                    $response['content'] = [
                        $this->successContentType($route) => [
                            'schema' => $this->successSchema($route),
                        ],
                    ];
                }

                return [$successStatus => $response];
            })
            ->all();

        if ($this->requiresSanctum($route)) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }

        if ($route->getName() === 'v2.auth.device-sessions.refresh') {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
        }

        if ($this->requiresDeveloperCredential($route)) {
            foreach ($successStatuses as $successStatus) {
                $responses[$successStatus]['headers'] = array_merge(
                    $responses[$successStatus]['headers'],
                    $this->developerQuotaHeaders(),
                );
            }
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
            $responses['429'] = ['$ref' => '#/components/responses/RateLimited'];
        }

        if ($route->parameterNames() !== []) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        if ($route->getName() === 'v2.sports.teams.stats.season-averages.show') {
            $responses['404'] = ['description' => 'No completed samples in the resolved scope, or unsupported sport/resource.',
                'headers' => $this->requestIdHeader(), 'content' => ['application/json' => ['schema' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/ApiErrorResponse'], ['$ref' => '#/components/schemas/TeamStatAverageNotFound'],
                ]]]]];
        }

        if ($this->queryParameterNames($route->getName() ?? '') !== [] || $this->requestBodyRequired($route) !== null
            || $route->getName() === 'v2.admin.nfl.research-retry.store') {
            $responses['422'] = ['$ref' => '#/components/responses/ValidationError'];
        }

        if ($this->supportsIdempotency($route)) {
            foreach ($successStatuses as $successStatus) {
                $responses[$successStatus]['headers'] = array_merge(
                    $responses[$successStatus]['headers'],
                    $this->idempotencyResponseHeaders(),
                );
            }
            $responses['409'] = ['$ref' => '#/components/responses/IdempotencyConflict'];
        }

        if ($this->usesDeclaredApiThrottle($route)) {
            $responses['429'] = ['$ref' => '#/components/responses/RateLimited'];
        }

        return $responses;
    }

    private function successContentType(Route $route): string
    {
        return $route->getName() === 'v2.user-bets.export'
            ? 'text/csv'
            : 'application/json';
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedResponses(): array
    {
        return [
            'Unauthenticated' => [
                'description' => 'Unauthenticated.',
                'headers' => $this->requestIdHeader(),
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiErrorResponse'],
                    ],
                ],
            ],
            'Forbidden' => [
                'description' => 'Authenticated user is not allowed to access this resource.',
                'headers' => $this->requestIdHeader(),
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiErrorResponse'],
                    ],
                ],
            ],
            'NotFound' => [
                'description' => 'Resource not found or sport slug is unsupported.',
                'headers' => $this->requestIdHeader(),
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiErrorResponse'],
                    ],
                ],
            ],
            'ValidationError' => [
                'description' => 'Validation error.',
                'headers' => $this->requestIdHeader(),
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiErrorResponse'],
                    ],
                ],
            ],
            'IdempotencyConflict' => [
                'description' => 'The idempotency key is already in progress or was reused with a different request payload.',
                'headers' => $this->requestIdHeader(),
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiErrorResponse'],
                    ],
                ],
            ],
            'RateLimited' => [
                'description' => 'The named API rate limit was exceeded.',
                'headers' => array_merge($this->requestIdHeader(), [
                    'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
                    'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
                    'Retry-After' => ['schema' => ['type' => 'integer']],
                ]),
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiErrorResponse'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function idempotencyResponseHeaders(): array
    {
        return [
            'Idempotency-Replayed' => [
                'description' => 'Whether this response was replayed from an earlier completed request.',
                'schema' => ['type' => 'boolean'],
            ],
            'Idempotency-Key-Expires-At' => [
                'description' => 'Expiry of the persisted retry result.',
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function developerQuotaHeaders(): array
    {
        return [
            'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
            'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
            'X-RateLimit-Reset' => [
                'description' => 'Unix timestamp when the monthly entitlement quota resets.',
                'schema' => ['type' => 'integer'],
            ],
            'RateLimit-Policy' => ['schema' => ['type' => 'string']],
        ];
    }

    private function supportsIdempotency(Route $route): bool
    {
        return collect($route->gatherMiddleware())->contains(
            fn (mixed $middleware): bool => $middleware === 'v2.idempotent'
                || str_starts_with((string) $middleware, 'v2.idempotent:'),
        );
    }

    private function oauthScope(string $method): string
    {
        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            ? 'mobile:read'
            : 'mobile:write';
    }

    /** @return list<string> */
    private function successStatuses(Route $route): array
    {
        return match ($route->getName()) {
            'v2.admin.nfl.research-retry.store', 'v2.admin.game-analysis.retry' => ['202'],
            'v2.auth.logout',
            'v2.auth.logout-all',
            'v2.auth.device-sessions.destroy',
            'v2.auth.device-sessions.push-registrations.destroy',
            'v2.cbb-brackets.destroy',
            'v2.user-bets.destroy' => ['204'],
            'v2.cbb-brackets.store',
            'v2.groups.store',
            'v2.user-bets.store',
            'v2.auth.device-sessions.store' => ['201'],
            'v2.alert-preferences.store',
            'v2.cbb-brackets.current.upsert',
            'v2.auth.device-sessions.push-registrations.store' => ['200', '201'],
            default => ['200'],
        };
    }

    private function requestBodyRequired(Route $route): ?bool
    {
        return match ($route->getName()) {
            'v2.auth.login',
            'v2.auth.passkeys.verify',
            'v2.auth.device-sessions.store',
            'v2.auth.device-sessions.refresh',
            'v2.auth.device-sessions.push-registrations.store',
            'v2.user-bets.store',
            'v2.cbb-brackets.store',
            'v2.cbb-brackets.current.upsert',
            'v2.groups.store',
            'v2.alert-preferences.store' => true,
            'v2.security.reports.csp', 'v2.security.reports.integrity',
            'v2.auth.passkeys.createOptions',
            'v2.user-bets.update',
            'v2.cbb-brackets.update',
            'v2.groups.update',
            'v2.alert-preferences.update' => false,
            default => null,
        };
    }

    private function usesDeclaredApiThrottle(Route $route): bool
    {
        return collect($route->gatherMiddleware())->contains(
            fn (mixed $middleware): bool => str_starts_with((string) $middleware, 'throttle:api-v2-')
                || preg_match('/^throttle:[0-9]+(?:,|$)/D', (string) $middleware) === 1,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function requestIdHeader(): array
    {
        return [
            'X-Request-ID' => [
                'description' => 'Request correlation identifier accepted from a safe client value or generated by the server.',
                'schema' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 128,
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function successSchema(Route $route): array
    {
        $name = $route->getName() ?? '';

        $schema = match ($name) {
            'v2.admin.payload-inspector' => 'PayloadInspectorResponse',
            'v2.admin.game-analysis.retry' => 'GameAnalysisRetryResponse',
            'v2.admin.nfl.research-retry.show', 'v2.admin.nfl.research-retry.store' => 'NflResearchRetryResponse',
            'v2.admin.game-analysis.retry' => 'GameAnalysisRetryResponse',
            'v2.alert-preferences.show',
            'v2.alert-preferences.store',
            'v2.alert-preferences.update' => 'AlertPreferenceResponse',
            'v2.auth.login', 'v2.auth.passkeys.verify' => 'TokenAuthResponse',
            'v2.auth.me' => 'AuthUserResponse',
            'v2.auth.context' => 'FrontendContextResponse',
            'v2.auth.passkeys.createOptions' => 'PasskeyAuthenticationOptionsResponse',
            'v2.auth.device-sessions.store',
            'v2.auth.device-sessions.refresh' => 'NativeDeviceTokenResponse',
            'v2.auth.device-sessions.push-registrations.store' => 'NativePushRegistrationResponse',
            'v2.cbb-brackets.index' => 'CbbBracketCollectionResponse',
            'v2.cbb-brackets.current.show' => 'NullableCbbBracketResponse',
            'v2.cbb-brackets.store',
            'v2.cbb-brackets.current.upsert',
            'v2.cbb-brackets.show',
            'v2.cbb-brackets.update' => 'CbbBracketResponse',
            'v2.cbb-brackets.leaderboard' => 'CbbBracketLeaderboardResponse',
            'v2.developer.sandbox.show' => 'DeveloperSandboxResponse',
            'v2.groups.index' => 'GroupCollectionResponse',
            'v2.groups.store', 'v2.groups.update' => 'GroupResponse',
            'v2.live-scoreboard.show' => 'LiveScoreboardResponse',
            'v2.sports.index' => 'SportCatalogResponse',
            'v2.sports.show' => 'SportContextResponse',
            'v2.sports.daily-picks.index' => 'MlbDailyPicksResponse',
            'v2.sports.forecasts.index' => 'SportForecastResponse',
            'v2.sports.games.index',
            'v2.sports.teams.games.index' => 'SportGameCollectionResponse',
            'v2.sports.games.show' => 'SportGameResponse',
            'v2.sports.games.page.show' => 'SportGamePageResponse',
            'v2.sports.games.research.show' => 'NflResearchResponse',
            'v2.sports.games.analysis.research.show' => 'GameResearchSummaryResponse',
            'v2.security.reports.csp', 'v2.security.reports.integrity' => 'SecurityReportResponse',
            'v2.sports.games.live-betting.show' => 'CfbLiveBettingResponse',
            'v2.sports.games.live-snapshot.show' => 'NflLiveSnapshotResponse',
            'v2.sports.games.market-history.show' => 'NflMarketHistoryResponse',
            'v2.sports.games.matchup-signals.show' => 'NflMatchupSignalsResponse',
            'v2.sports.games.depth-charts.show',
            'v2.sports.teams.depth-charts.show' => 'SportDepthChartResponse',
            'v2.sports.games.trends.show' => 'SportGameTrendsResponse',
            'v2.sports.teams.trends.show' => 'SportTeamTrendResponse',
            'v2.sports.injuries.index' => 'SportInjuryCollectionResponse',
            'v2.sports.leaderboards.players.index' => 'SportPlayerLeaderboardResponse',
            'v2.sports.leaderboards.players.available-seasons' => 'SportAvailableSeasonsResponse',
            'v2.sports.markets.futures.index',
            'v2.sports.teams.futures.index' => 'SportFuturesOddCollectionResponse',
            'v2.sports.markets.player-props.index',
            'v2.sports.player-props.index',
            'v2.sports.games.player-props.index',
            'v2.sports.players.player-props.index' => 'SportPlayerPropCollectionResponse',
            'v2.sports.player-props.board' => 'SportPlayerPropBoardResponse',
            'v2.sports.metrics.teams.index' => 'SportTeamMetricCollectionResponse',
            'v2.sports.metrics.teams.available-seasons' => 'SportAvailableSeasonsResponse',
            'v2.sports.teams.metrics.show' => 'SportTeamMetricResponse',
            'v2.sports.players.index',
            'v2.sports.teams.players.index' => 'SportPlayerCollectionResponse',
            'v2.sports.players.show' => 'SportPlayerResponse',
            'v2.sports.predictions.index' => 'SportPredictionCollectionResponse',
            'v2.sports.predictions.show',
            'v2.sports.games.prediction.show' => 'SportPredictionResponse',
            'v2.sports.predictions.available-dates',
            'v2.sports.stats.player.available-dates',
            'v2.sports.stats.team.available-dates' => 'SportAvailableDatesResponse',
            'v2.sports.predictions.available-seasons',
            'v2.sports.stats.player.available-seasons',
            'v2.sports.stats.team.available-seasons' => 'SportAvailableSeasonsResponse',
            'v2.sports.signals.index' => 'SportSignalResponse',
            'v2.sports.stats.player.index',
            'v2.sports.stats.team.index' => 'SportStatCollectionResponse',
            'v2.sports.stats.team.season-averages.index' => 'SportTeamStatAverageCollectionResponse',
            'v2.sports.teams.stats.season-averages.show' => 'SportTeamStatAverageResponse',
            'v2.sports.teams.index' => 'SportTeamCollectionResponse',
            'v2.sports.teams.show' => 'SportTeamResponse',
            'v2.user-bets.index' => 'UserBetIndexResponse',
            'v2.user-bets.store', 'v2.user-bets.update' => 'UserBetResponse',
            'v2.user-bets.export' => 'UserBetCsvExport',
            default => throw new \LogicException("No OpenAPI response schema is mapped for [{$name}]."),
        };

        return ['$ref' => "#/components/schemas/{$schema}"];
    }

    private function requestSchemaReference(Route $route): string
    {
        return match ($route->getName()) {
            'v2.security.reports.csp', 'v2.security.reports.integrity' => '#/components/schemas/SecurityReportRequest',
            'v2.user-bets.store' => '#/components/schemas/UserBetStoreRequest',
            'v2.user-bets.update' => '#/components/schemas/UserBetUpdateRequest',
            'v2.alert-preferences.store' => '#/components/schemas/AlertPreferenceStoreRequest',
            'v2.alert-preferences.update' => '#/components/schemas/AlertPreferenceUpdateRequest',
            'v2.auth.login' => '#/components/schemas/LoginRequest',
            'v2.auth.passkeys.createOptions' => '#/components/schemas/PasskeyAuthenticationOptionsRequest',
            'v2.auth.passkeys.verify' => '#/components/schemas/PasskeyAuthenticationVerifyRequest',
            'v2.auth.device-sessions.store' => '#/components/schemas/NativeDeviceSessionStoreRequest',
            'v2.auth.device-sessions.refresh' => '#/components/schemas/NativeDeviceSessionRefreshRequest',
            'v2.auth.device-sessions.push-registrations.store' => '#/components/schemas/NativePushRegistrationStoreRequest',
            'v2.cbb-brackets.store' => '#/components/schemas/CbbBracketStoreRequest',
            'v2.cbb-brackets.current.upsert' => '#/components/schemas/CbbBracketUpsertRequest',
            'v2.cbb-brackets.update' => '#/components/schemas/CbbBracketUpdateRequest',
            'v2.groups.store' => '#/components/schemas/GroupStoreRequest',
            'v2.groups.update' => '#/components/schemas/GroupUpdateRequest',
            default => throw new \LogicException('No OpenAPI request schema is mapped for ['.($route->getName() ?? '').'].'),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function userBetWriteProperties(): array
    {
        return [
            'bet_amount' => ['type' => 'number', 'minimum' => 0],
            'odds' => ['type' => 'string'],
            'bet_type' => ['type' => 'string', 'enum' => ['spread', 'moneyline', 'total_over', 'total_under']],
            'selection_side' => ['type' => ['string', 'null'], 'maxLength' => 20],
            'selection_label' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'line' => ['type' => ['number', 'null']],
            'notes' => ['type' => ['string', 'null'], 'maxLength' => 1000],
            'placed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
        ];
    }

    /**
     * Schemas for route-specific request and response contracts outside the canonical game resource.
     *
     * @return array<string, array<string, mixed>>
     */
    private function contractSchemas(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableInteger = ['type' => ['integer', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableDateTime = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $openObject = ['type' => 'object', 'additionalProperties' => true];
        $nullableOpenObject = ['type' => ['object', 'null'], 'additionalProperties' => true];

        $sportTeam = $this->fixedObjectSchema([
            'id', 'sport', 'espn_id', 'abbreviation', 'location', 'name', 'nickname',
            'display_name', 'short_display_name', 'conference', 'league', 'division',
            'color', 'alternate_color', 'logo_url', 'created_at', 'updated_at',
        ], [
            'id' => $nullableInteger,
            'sport' => ['type' => 'string'],
            'created_at' => $nullableDateTime,
            'updated_at' => $nullableDateTime,
        ]);
        $sportPlayer = $this->fixedObjectSchema([
            'id', 'sport', 'team_id', 'espn_id', 'first_name', 'last_name', 'full_name',
            'display_name', 'jersey_number', 'position', 'height', 'weight', 'age',
            'experience', 'year', 'college', 'hometown', 'status', 'batting_hand',
            'throwing_hand', 'headshot_url', 'team', 'active_injuries', 'created_at', 'updated_at',
        ], [
            'id' => $nullableInteger,
            'team_id' => $nullableInteger,
            'sport' => ['type' => 'string'],
            'team' => $nullableOpenObject,
            'active_injuries' => ['type' => ['array', 'null'], 'items' => $this->fixedObjectSchema(['id', 'status', 'detail', 'type', 'return_date', 'source_updated_at', 'source_updated_at_raw', 'source_clock_status'], ['id' => $nullableInteger, 'status' => $nullableString, 'detail' => $nullableString, 'type' => $nullableString, 'return_date' => ['type' => ['string', 'null'], 'format' => 'date'], 'source_updated_at' => ['type' => 'null', 'description' => 'No qualified observation instant is available for legacy injury clocks.'], 'source_updated_at_raw' => $nullableString, 'source_clock_status' => ['type' => 'string', 'enum' => ['unverified_storage_clock']]]), 'description' => 'Stored active injury observations for detail reads; null when coverage was not loaded. Empty does not establish complete provider coverage.'],
            'created_at' => $nullableDateTime,
            'updated_at' => $nullableDateTime,
        ]);
        $sportPlayerProp = $this->fixedObjectSchema([
            'id', 'sport', 'game_id', 'player_id', 'player_name', 'market', 'bookmaker',
            'line', 'over_price', 'under_price', 'prices', 'recommendation', 'grading',
            'player', 'game', 'fetched_at', 'created_at', 'updated_at',
        ], [
            'id' => $nullableInteger,
            'game_id' => $nullableInteger,
            'player_id' => $nullableInteger,
            'sport' => ['type' => 'string'],
            'line' => $nullableNumber,
            'prices' => $openObject,
            'recommendation' => $openObject,
            'grading' => $openObject,
            'player' => $nullableOpenObject,
            'game' => $nullableOpenObject,
            'fetched_at' => $nullableDateTime,
            'created_at' => $nullableDateTime,
            'updated_at' => $nullableDateTime,
        ]);
        $sportPlayerProp['properties']['is_current'] = ['type' => 'boolean',
            'description' => 'Present for CFB prop revisions; omitted by other sports.'];
        $sportPrediction = $this->fixedObjectSchema([
            'id', 'sport', 'game_id', 'home_team_id', 'away_team_id', 'game', 'status',
            'pick', 'projection', 'home_win_probability', 'away_win_probability',
            'win_probability', 'predicted_spread', 'spread_convention', 'predicted_total', 'confidence_score',
            'confidence_level', 'confidence_context', 'public_recommendation', 'value_signal',
            'market_aware_projection', 'recommendation', 'pro_signal_layer', 'period_insights',
            'cfb_signal_context', 'home_elo', 'away_elo', 'home_team_elo', 'away_team_elo',
            'home_pitcher_elo', 'away_pitcher_elo', 'home_combined_elo', 'away_combined_elo',
            'actual_spread', 'actual_total', 'spread_error', 'total_error', 'winner_correct',
            'total_pick_side', 'total_pick_line', 'total_pick_result', 'total_pick_edge',
            'total_result', 'graded_at', 'live_predicted_spread', 'live_predicted_total',
            'live_win_probability', 'live_seconds_remaining', 'live_outs_remaining',
            'live_updated_at', 'depth_chart_context', 'market_summary', 'audit_context',
            'created_at', 'updated_at',
        ], [
            'id' => $nullableInteger,
            'game_id' => $nullableInteger,
            'home_team_id' => $nullableInteger,
            'away_team_id' => $nullableInteger,
            'sport' => ['type' => 'string'],
            'spread_convention' => ['type' => 'string', 'enum' => ['home_margin', 'home_handicap']],
            'game' => $nullableOpenObject,
            'pick' => $openObject,
            'projection' => $openObject,
            'period_insights' => ['type' => 'array', 'items' => $openObject],
            'market_summary' => $openObject,
            'graded_at' => $nullableDateTime,
            'live_updated_at' => $nullableDateTime,
            'created_at' => $nullableDateTime,
            'updated_at' => $nullableDateTime,
        ]);
        $sportPrediction['properties']['comparison_forecasts'] = $nullableOpenObject;
        $sportPrediction['properties']['market_evaluation'] = $nullableOpenObject;
        $sportPrediction['properties']['forecast_coordination'] = $nullableOpenObject;
        $sportPrediction['properties']['raw_home_win_probability'] = $nullableNumber;
        $sportPrediction['properties']['id'] = ['type' => ['integer', 'string', 'null']];
        $sportPrediction['properties']['cfb_signal_context'] = [
            'type' => ['object', 'null'],
            'properties' => ['closing_market_evidence' => ['oneOf' => [
                ['$ref' => '#/components/schemas/CfbClosingMarketEvidence'], ['type' => 'null'],
            ]]],
            'additionalProperties' => true,
        ];
        $sportStat = $this->fixedObjectSchema([
            'id', 'sport', 'type', 'game_id', 'team_id', 'player_id', 'stat_type',
            'team_type', 'season', 'season_type', 'game_date', 'game', 'team', 'player',
            'stats', 'created_at', 'updated_at',
        ], [
            'id' => $nullableInteger,
            'game_id' => $nullableInteger,
            'team_id' => $nullableInteger,
            'player_id' => $nullableInteger,
            'season' => $nullableInteger,
            'sport' => ['type' => 'string'],
            'spread_convention' => ['type' => 'string', 'enum' => ['home_margin', 'home_handicap']],
            'game' => $nullableOpenObject,
            'team' => $nullableOpenObject,
            'player' => $nullableOpenObject,
            'stats' => $openObject,
            'created_at' => $nullableDateTime,
            'updated_at' => $nullableDateTime,
        ]);
        $sportFuturesOdd = $this->fixedObjectSchema([
            'id', 'sport', 'season', 'odds_api_sport_key', 'event_id', 'event_name',
            'commence_time', 'bookmaker', 'market_key', 'market_last_update', 'outcome',
            'entity', 'fetched_at', 'created_at', 'updated_at',
        ], [
            'id' => $nullableInteger,
            'season' => $nullableInteger,
            'sport' => ['type' => 'string'],
            'outcome' => $openObject,
            'entity' => $nullableOpenObject,
            'commence_time' => $nullableDateTime,
            'market_last_update' => $nullableDateTime,
            'fetched_at' => $nullableDateTime,
            'created_at' => $nullableDateTime,
            'updated_at' => $nullableDateTime,
        ]);

        return [
            'LoginRequest' => $this->requestObject(['email', 'password'], [
                'email' => ['type' => 'string', 'format' => 'email'],
                'password' => ['type' => 'string'],
                'device_name' => ['type' => ['string', 'null'], 'maxLength' => 120],
                'token_profile' => ['type' => 'string', 'enum' => ['interactive', 'read_only'], 'default' => 'interactive'],
            ]),
            'PasskeyAuthenticationOptionsRequest' => $this->requestObject([], [
                'email' => ['type' => ['string', 'null'], 'format' => 'email'],
            ]),
            'PasskeyAuthenticationVerifyRequest' => $this->requestObject([
                'challenge_id', 'credential_id', 'client_data_json', 'authenticator_data', 'signature',
            ], [
                'challenge_id' => ['type' => 'string', 'maxLength' => 255],
                'credential_id' => ['type' => 'string', 'maxLength' => 512],
                'client_data_json' => ['type' => 'string', 'maxLength' => 4096],
                'authenticator_data' => ['type' => 'string', 'maxLength' => 4096],
                'signature' => ['type' => 'string', 'maxLength' => 4096],
                'device_name' => ['type' => ['string', 'null'], 'maxLength' => 120],
                'token_profile' => ['type' => 'string', 'enum' => ['interactive', 'read_only'], 'default' => 'interactive'],
            ]),
            'AlertPreferenceStoreRequest' => $this->alertPreferenceRequest(true),
            'AlertPreferenceUpdateRequest' => $this->alertPreferenceRequest(false),
            'GroupStoreRequest' => $this->requestObject(['name'], [
                'name' => ['type' => 'string', 'maxLength' => 255],
                'type' => ['type' => ['string', 'null'], 'maxLength' => 100],
                'sport' => ['type' => ['string', 'null'], 'maxLength' => 50],
                'season' => ['type' => ['integer', 'null'], 'minimum' => 2000, 'maximum' => 2100],
            ]),
            'GroupUpdateRequest' => $this->requestObject([], [
                'name' => ['type' => 'string', 'maxLength' => 255],
            ]),
            'CbbBracketStoreRequest' => $this->cbbBracketRequest(['season'], true),
            'CbbBracketUpsertRequest' => $this->cbbBracketRequest(['season', 'picks'], false),
            'CbbBracketUpdateRequest' => $this->cbbBracketRequest([], false),

            'AuthUser' => $this->fixedObjectSchema([
                'id', 'name', 'email', 'email_verified_at', 'is_admin', 'is_subscribed',
                'is_founding_user', 'tier', 'roles', 'permissions',
            ], [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'email_verified_at' => $nullableDateTime,
                'is_admin' => ['type' => 'boolean'],
                'is_subscribed' => ['type' => 'boolean'],
                'is_founding_user' => ['type' => 'boolean'],
                'tier' => $openObject,
                'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                'permissions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]),
            'AuthUserResponse' => $this->itemEnvelope('AuthUser', false),
            'FrontendContextResponse' => $this->itemEnvelope('FrontendContext', true),
            'FrontendContext' => $this->fixedObjectSchema(['name', 'auth', 'subscription'], [
                'name' => ['type' => 'string'],
                'auth' => $this->fixedObjectSchema(['user'], ['user' => ['$ref' => '#/components/schemas/AuthUser']]),
                'subscription' => $this->fixedObjectSchema(['tier', 'tier_name', 'is_subscribed', 'is_founding_user', 'features', 'tiers_enabled', 'tiers_bypassed'], [
                    'tier' => ['type' => 'string'], 'tier_name' => ['type' => 'string'],
                    'is_subscribed' => ['type' => 'boolean'], 'is_founding_user' => ['type' => 'boolean'],
                    'features' => ['oneOf' => [$openObject, ['type' => 'array', 'maxItems' => 0]]],
                    'tiers_enabled' => ['type' => 'boolean'], 'tiers_bypassed' => ['type' => 'boolean'],
                ]),
            ]),
            'TokenAuthResponse' => $this->fixedObjectSchema(['token_type', 'access_token', 'user', 'token_profile', 'abilities', 'expires_at', 'expires_epoch', 'expires_in'], [
                'token_type' => ['type' => 'string', 'const' => 'Bearer'],
                'access_token' => ['type' => 'string'],
                'token_profile' => ['type' => 'string', 'enum' => ['legacy_interactive', 'interactive', 'read_only']],
                'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Exact UTC expiry; null while legacy issuance remains active.'],
                'expires_epoch' => ['type' => ['integer', 'null']],
                'expires_in' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'user' => ['$ref' => '#/components/schemas/AuthUser'],
            ]),
            'PasskeyAuthenticationOptionsResponse' => [
                'type' => 'object',
                'description' => 'WebAuthn PublicKeyCredentialRequestOptions plus the server challenge identifier.',
                'properties' => [
                    'challenge_id' => ['type' => 'string'],
                    'challenge' => ['type' => 'string'],
                    'rpId' => ['type' => 'string'],
                    'timeout' => ['type' => 'integer'],
                    'userVerification' => ['type' => 'string'],
                    'allowCredentials' => ['type' => 'array', 'items' => $openObject],
                ],
                'additionalProperties' => true,
            ],
            'AlertPreference' => $this->fixedObjectSchema([
                'id', 'enabled', 'sports', 'notification_types', 'minimum_edge',
                'time_window_start', 'time_window_end', 'digest_mode', 'digest_time',
                'daily_digest_subscribed', 'phone_number', 'created_at', 'updated_at',
            ], [
                'id' => ['type' => 'integer'],
                'enabled' => ['type' => 'boolean'],
                'sports' => ['type' => 'array', 'items' => ['type' => 'string']],
                'notification_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                'minimum_edge' => ['type' => 'number'],
                'daily_digest_subscribed' => ['type' => 'boolean'],
                'phone_number' => $nullableString,
                'created_at' => $nullableDateTime,
                'updated_at' => $nullableDateTime,
            ]),
            'AlertPreferenceResponse' => $this->itemEnvelope('AlertPreference', false),
            'Group' => $this->fixedObjectSchema([
                'id', 'public_id', 'name', 'type', 'sport', 'season', 'owner_id', 'settings',
            ], [
                'id' => ['type' => 'integer'],
                'public_id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'season' => $nullableInteger,
                'owner_id' => ['type' => 'integer'],
                'settings' => $openObject,
            ]),
            'GroupResponse' => $this->itemEnvelope('Group', false),
            'GroupCollectionResponse' => $this->collectionEnvelope('Group', false),
            'CbbBracket' => $this->cbbBracketSchema(),
            'CbbBracketResponse' => $this->itemEnvelope('CbbBracket', false),
            'NullableCbbBracketResponse' => $this->itemEnvelope('CbbBracket', false, true),
            'CbbBracketCollectionResponse' => $this->collectionEnvelope('CbbBracket', false),
            'CbbBracketLeaderboardResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => $this->fixedObjectSchema([
                        'rank', 'bracket_id', 'bracket_public_id', 'bracket_name', 'user_id',
                        'user_name', 'points_earned', 'max_points_remaining', 'correct_picks',
                        'incorrect_picks', 'submitted_at', 'updated_at',
                    ])],
                ],
                'additionalProperties' => false,
            ],
            'DeveloperSandboxResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => $this->fixedObjectSchema([
                    'mode', 'organization_id', 'credential_id', 'product', 'scope', 'request_id',
                ], [
                    'mode' => ['type' => 'string', 'const' => 'sandbox'],
                    'organization_id' => ['type' => 'string'],
                    'credential_id' => ['type' => 'string'],
                    'product' => ['type' => 'string'],
                    'scope' => ['type' => 'string', 'const' => 'sandbox:read'],
                    'request_id' => ['type' => 'string'],
                ])],
                'additionalProperties' => false,
            ],
            'PayloadInspectorResponse' => $this->sportCustomEnvelope('PayloadInspectorData'),
            'PayloadInspectorData' => $openObject,
            'LiveScoreboardResponse' => $this->sportCustomEnvelope('LiveScoreboardData'),
            'LiveScoreboardData' => $this->fixedObjectSchema(['games', 'updated_at'], [
                'games' => ['type' => 'array', 'items' => $openObject],
                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),

            'SportContext' => $this->fixedObjectSchema(['slug', 'label', 'namespace', 'capabilities', 'web', 'access'], [
                'slug' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'namespace' => ['type' => 'string'],
                'capabilities' => $openObject,
                'web' => $openObject,
                'access' => $openObject,
            ]),
            'SportCatalogResponse' => $this->collectionEnvelope('SportContext', true),
            'SportContextResponse' => $this->itemEnvelope('SportContext', true),
            'SportTeam' => $sportTeam,
            'SportTeamResponse' => $this->sportItemEnvelope('SportTeam'),
            'SportTeamCollectionResponse' => $this->sportCollectionEnvelope('SportTeam'),
            'SportPlayer' => $sportPlayer,
            'SportPlayerResponse' => $this->sportItemEnvelope('SportPlayer'),
            'SportPlayerCollectionResponse' => $this->sportCollectionEnvelope('SportPlayer'),
            'SportPlayerProp' => $sportPlayerProp,
            'SportPlayerPropCollectionResponse' => $this->sportCollectionEnvelope('SportPlayerProp'),
            'SportPrediction' => $sportPrediction,
            'CfbClosingMarketEvidence' => $this->cfbClosingMarketEvidenceSchema(),
            'SportPredictionResponse' => $this->sportItemEnvelope('SportPrediction', true),
            'SportPredictionCollectionResponse' => $this->sportCollectionEnvelope('SportPrediction'),
            'SportStat' => $sportStat,
            'SportStatCollectionResponse' => $this->sportCollectionEnvelope('SportStat'),
            'SportFuturesOdd' => $sportFuturesOdd,
            'SportFuturesOddCollectionResponse' => $this->sportCollectionEnvelope('SportFuturesOdd'),
            'SportTeamMetric' => [
                'type' => 'object',
                'required' => ['id', 'sport', 'team_id', 'season', 'season_type', 'wins', 'losses', 'games_played', 'record', 'record_label', 'team', 'calculation_date', 'created_at', 'updated_at'],
                'properties' => [
                    'id' => $nullableInteger, 'sport' => ['type' => 'string'], 'team_id' => $nullableInteger,
                    'season' => $nullableInteger, 'season_type' => $nullableString, 'wins' => $nullableInteger,
                    'losses' => $nullableInteger, 'games_played' => ['type' => 'integer'], 'record' => $openObject,
                    'record_label' => $nullableString, 'team' => $nullableOpenObject, 'calculation_date' => $nullableString,
                    'created_at' => $nullableDateTime, 'updated_at' => $nullableDateTime,
                ],
                'description' => 'Known metric identity and record fields. Additional metric columns vary by sport.',
                'additionalProperties' => true,
            ],
            'SportTeamMetricResponse' => $this->sportItemEnvelope('SportTeamMetric'),
            'SportTeamMetricCollectionResponse' => $this->sportCollectionEnvelope('SportTeamMetric'),
            'SportInjury' => $this->sportInjurySchema(),
            'SportInjuryCollectionResponse' => $this->injuryCollectionEnvelope(),
            'SportAvailableDatesResponse' => $this->sportCustomEnvelope('SportAvailableDates'),
            'SportAvailableDates' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
            'SportAvailableSeasonsResponse' => $this->sportCustomEnvelope('SportAvailableSeasons'),
            'SportAvailableSeasons' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'SportPlayerLeaderboardResponse' => $this->sportCustomEnvelope('SportPlayerLeaderboardRows'),
            'SportPlayerLeaderboardRows' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    ...array_fill_keys(['points_per_game', 'rebounds_per_game', 'assists_per_game', 'turnovers_per_game', 'steals_per_game', 'blocks_per_game', 'three_pointers_made_per_game', 'minutes_per_game', 'field_goals_made_per_game', 'field_goals_attempted_per_game', 'free_throws_made_per_game', 'free_throws_attempted_per_game', 'field_goal_percentage', 'three_point_percentage', 'free_throw_percentage', 'estimated_epa_per_game', 'estimated_epa_per_36'], $nullableNumber),
                    'field_samples' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0], 'description' => 'Basketball non-null observation counts for each stored field.'],
                    'complete_box_score_games' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Basketball rows with all required proxy counts present and consistent. Estimated EPA is unavailable unless every included row qualifies.'],
                    'minutes_samples' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Basketball rows with a valid observed minutes value, including zero. Per36 additionally requires positive minutes and a complete matching cohort.'],
                    'shooting_samples' => ['type' => 'object', 'properties' => array_fill_keys(['field_goals', 'three_point', 'free_throws'], ['type' => 'integer', 'minimum' => 0]), 'additionalProperties' => false],
                ],
                'additionalProperties' => true,
                'description' => 'Sport-specific leaderboard rows. Basketball averages preserve unknown observations as null and disclose their sample counts; other sports retain their own aggregate contracts.',
            ]],
            'SportDepthChartResponse' => $this->sportCustomEnvelope('SportDepthChartData'),
            'SportDepthChartData' => $openObject,
            'SportForecastResponse' => $this->sportCustomEnvelope('SportForecastData'),
            'SportForecastData' => ['type' => 'array', 'items' => $openObject],
            'SportSignalResponse' => $this->sportCustomEnvelope('SportSignalData'),
            'SportSignalData' => $openObject,
            'SportTeamTrendResponse' => $this->sportCustomEnvelope('SportTeamTrendData'),
            'SportTeamTrendData' => $openObject,
            'SportGameTrendsResponse' => $this->sportCustomEnvelope('SportGameTrendsData'),
            'SportGameTrendsData' => $this->fixedObjectSchema(['home', 'away'], [
                'home' => $openObject,
                'away' => $openObject,
            ]),
            'SecurityReportRequest' => ['oneOf' => [$openObject, ['type' => 'array', 'items' => $openObject]]],
            'SecurityReportResponse' => $this->fixedObjectSchema(['ok'], ['ok' => ['const' => true]]),
            'NflResearchRetryResponse' => $this->objectSchemaWithOptionalFields(['data'], [
                'data' => $this->objectSchemaWithOptionalFields(['status'], [
                    'status' => ['type' => 'string', 'enum' => ['idle', 'queued', 'running', 'completed', 'blocked', 'failed']],
                    'run_id' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'requested_by' => ['type' => 'integer'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ]),
                'message' => ['type' => 'string'],
            ]),
            'NflResearchResponse' => $this->sportCustomEnvelope('NflResearchData'),
            'GameResearchSummaryResponse' => $this->sportCustomEnvelope('GameResearchSummaryData'),
            'GameAnalysisRetryResponse' => $this->objectSchemaWithOptionalFields(['data', 'message'], [
                'data' => $this->objectSchemaWithOptionalFields(['status', 'game_id', 'sport'], [
                    'status' => ['type' => 'string', 'enum' => ['queued']], 'game_id' => ['type' => 'integer'], 'sport' => ['type' => 'string'],
                ]),
                'message' => ['type' => 'string'],
            ]),
            'GameResearchSummaryData' => $this->objectSchemaWithOptionalFields(['sport', 'game_id', 'status', 'applicability', 'supporting', 'concerns', 'unresolved', 'sources', 'review'], [
                'schema_version' => ['type' => 'string'], 'sport' => ['type' => 'string'], 'game_id' => ['type' => 'integer'],
                'sport_event_id' => ['type' => ['integer', 'null']], 'report_id' => ['type' => ['integer', 'null']],
                'status' => ['type' => 'string', 'enum' => ['ready', 'partial', 'unavailable']],
                'applicability' => ['type' => 'string', 'enum' => ['current', 'outdated', 'expired', 'archived']],
                'assessed_at' => $nullableDateTime, 'expires_at' => $nullableDateTime,
                'supporting' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GameResearchFinding']],
                'concerns' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GameResearchFinding']],
                'facts' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GameResearchFinding']],
                'season_evidence' => $nullableOpenObject,
                'unresolved' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GameResearchFinding']],
                'sources' => ['type' => 'array', 'items' => $this->objectSchemaWithOptionalFields(['url'], ['url' => ['type' => 'string', 'format' => 'uri'], 'title' => ['type' => 'string'], 'published_at' => $nullableDateTime, 'observed_at' => $nullableDateTime, 'provider' => ['type' => 'string'], 'publisher' => ['type' => 'string'], 'source_label' => ['type' => 'string'], 'source_scope' => ['type' => 'string'], 'parser_version' => ['type' => 'string'], 'snapshot_id' => ['type' => 'integer'], 'observation_id' => ['type' => 'integer'], 'payload_hash' => ['type' => 'string'], 'material_hash' => ['type' => 'string']])],
                'reason_codes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'capabilities' => $this->objectSchemaWithOptionalFields(['can_retry'], ['can_retry' => ['type' => 'boolean']]),
                'review' => $this->objectSchemaWithOptionalFields(['status'], [
                    'status' => ['type' => 'string'], 'reviewed_at' => $nullableDateTime,
                    'summary' => ['type' => ['string', 'null']], 'context_summary' => ['type' => ['string', 'null']], 'key_factors' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'risk_flags' => ['type' => 'array', 'items' => ['type' => 'string']], 'reason_code' => ['type' => ['string', 'null']],
                ]),
            ]),
            'GameResearchFinding' => $this->objectSchemaWithOptionalFields([], [
                'claim' => ['type' => 'string'], 'interpretation' => ['type' => 'string'], 'topic' => ['type' => 'string'], 'scope' => ['type' => 'string'],
                'team_side' => ['type' => 'string'],
                'source_url' => ['type' => ['string', 'null']], 'reason_code' => ['type' => 'string'],
                'evidence_kind' => ['type' => 'string'], 'evidence_reference' => ['type' => 'string'], 'evidence_status' => ['type' => 'string'],
                'provider' => ['type' => 'string'], 'publisher' => ['type' => 'string'], 'source_label' => ['type' => 'string'],
                'source_scope' => ['type' => 'string'], 'parser_version' => ['type' => 'string'],
                'snapshot_id' => ['type' => 'integer'], 'observation_id' => ['type' => 'integer'],
                'payload_hash' => ['type' => 'string'], 'material_hash' => ['type' => 'string'],
                'json_pointer' => ['type' => 'string'], 'espn_athlete_id' => ['type' => 'string'],
                'raw_status' => ['type' => ['string', 'null']], 'normalized_status' => ['type' => 'string'],
                'source_time_field' => ['type' => ['string', 'null']], 'source_time_raw' => ['type' => ['string', 'null']],
                'observed_at' => $nullableDateTime, 'published_at' => $nullableDateTime,
            ]),
            'NflResearchData' => $this->fixedObjectSchema(['game_id', 'revisions'], [
                'game_id' => ['type' => 'integer'],
                'revisions' => ['type' => 'array', 'maxItems' => 20, 'items' => ['$ref' => '#/components/schemas/NflResearchRevision']],
            ]),
            'NflResearchRevision' => $this->fixedObjectSchema(['id', 'created_at', 'baseline', 'revised', 'brief', 'market', 'evaluation'], [
                'id' => ['type' => 'integer'], 'created_at' => $nullableDateTime,
                'baseline' => $nullableOpenObject, 'revised' => $nullableOpenObject,
                'brief' => $nullableOpenObject, 'market' => $nullableOpenObject, 'evaluation' => $nullableOpenObject,
            ]),
            'CfbLiveBettingResponse' => $this->fixedObjectSchema(['data', 'history', 'meta'], [
                'data' => ['oneOf' => [['$ref' => '#/components/schemas/CfbLiveBettingSnapshot'], ['type' => 'null']]],
                'history' => ['type' => 'array', 'maxItems' => 25, 'items' => ['$ref' => '#/components/schemas/CfbLiveBettingSnapshot']],
                'meta' => $openObject,
            ]),
            'CfbLiveBettingSnapshot' => $this->fixedObjectSchema(['id', 'source', 'observed_at', 'status', 'stale', 'pregame', 'state', 'projection', 'markets', 'props'], [
                'id' => ['type' => 'integer'], 'source' => ['type' => 'string'],
                'observed_at' => ['type' => 'string', 'format' => 'date-time'],
                'status' => ['type' => 'string'], 'stale' => ['type' => 'boolean'],
                'pregame' => $openObject, 'state' => $openObject, 'projection' => $nullableOpenObject,
                'markets' => ['type' => 'array', 'items' => $openObject],
                'props' => ['type' => 'array', 'items' => $openObject],
            ]),
            'NflLiveSnapshotResponse' => $this->itemEnvelope('NflLiveSnapshotData', false),
            'NflLiveSnapshotData' => $this->fixedObjectSchema(['game', 'projection', 'source_updated_at', 'record_updated_at', 'record_updated_at_raw', 'record_clock_status', 'source_clock_status', 'generated_at', 'provisional', 'model_kind', 'calibrated', 'clock_scope', 'warning'], [
                'game' => $openObject, 'projection' => $nullableOpenObject,
                'source_updated_at' => ['type' => 'null', 'description' => 'No qualified provider observation instant is available.'],
                'record_updated_at' => ['type' => 'null', 'description' => 'Unqualified stored record text is not an observation instant.'],
                'record_updated_at_raw' => $nullableString,
                'record_clock_status' => ['const' => 'unverified_storage_clock'],
                'source_clock_status' => ['const' => 'observation_unavailable'],
                'generated_at' => ['type' => 'string', 'format' => 'date-time'],
                'provisional' => ['const' => true], 'calibrated' => ['const' => false],
                'model_kind' => ['const' => 'score_clock_heuristic'],
                'clock_scope' => ['enum' => ['current_overtime_period', 'regulation']], 'warning' => ['type' => 'string'],
            ]),
            'NflMarketHistoryResponse' => $this->itemEnvelope('NflMarketHistoryData', true),
            'NflMarketHistoryData' => $openObject,
            'NflMatchupSignalsResponse' => $this->itemEnvelope('NflMatchupSignalsData', true),
            'NflMatchupSignalsData' => $this->fixedObjectSchema(['role', 'affects_prediction', 'matchup', 'situational', 'generated_at'], [
                'role' => ['const' => 'independent_matchup_analysis'], 'affects_prediction' => ['const' => false],
                'matchup' => $openObject, 'situational' => $openObject,
                'generated_at' => ['type' => 'string', 'format' => 'date-time'],
            ]),
            'SportGamePageResponse' => $this->sportCustomEnvelope('SportGamePageData'),
            'SportGamePageData' => ['oneOf' => [
                ['$ref' => '#/components/schemas/MlbGamePageData'],
                ['$ref' => '#/components/schemas/NflGamePageData'],
            ]],
            'NflGamePageData' => $this->fixedObjectSchema(['game', 'prediction', 'team_stats'], [
                'game' => ['$ref' => '#/components/schemas/SportGame'],
                'prediction' => ['oneOf' => [['$ref' => '#/components/schemas/SportPrediction'], ['type' => 'null']]],
                'team_stats' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SportStat']],
            ]),
            'MlbGamePageData' => $this->fixedObjectSchema([
                'game', 'prediction', 'historical_prediction', 'recent_games', 'metrics', 'depth_charts_available',
            ], [
                'game' => ['$ref' => '#/components/schemas/SportGame'],
                'prediction' => ['oneOf' => [['$ref' => '#/components/schemas/SportPrediction'], ['type' => 'null']]],
                'historical_prediction' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => 'Display-only legacy archive or unverified historical forecast; never active betting evidence.'],
                'recent_games' => $openObject,
                'metrics' => $openObject,
                'depth_charts_available' => ['type' => 'boolean'],
            ]),
            'SportPlayerPropBoardResponse' => $this->sportCustomEnvelope('SportPlayerPropBoardData'),
            'SportPlayerPropBoardData' => $openObject,
            'SportTeamStatAverageCollectionResponse' => $this->teamAverageEnvelope('SportTeamStatAverageRows'),
            'SportTeamStatAverageRows' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SportTeamStatAverage']],
            'SportTeamStatAverageResponse' => $this->teamAverageEnvelope('SportTeamStatAverage'),
            'SportTeamStatAverage' => $this->teamAverageSchema(),
            'TeamStatAverageScope' => $this->teamAverageScopeSchema(),
            'TeamStatAverageNotFound' => $this->teamAverageNotFoundSchema(),
            'InjuryObservationFreshness' => $this->injuryFreshnessSchema(),
            'MlbDailyPicksResponse' => $this->sportCustomEnvelope('MlbDailyPicksData'),
            'MlbDailyPicksData' => $this->fixedObjectSchema(['summary', 'board_health', 'top_picks', 'candidates'], [
                'summary' => ['$ref' => '#/components/schemas/MlbDailyStoredAssessment'],
                'board_health' => ['$ref' => '#/components/schemas/MlbDailyStoredAssessment'],
                'top_picks' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MlbDailyPickCandidate']],
                'candidates' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MlbDailyPickCandidate']],
            ], true),
            'MlbDailyStoredAssessment' => $this->fixedObjectSchema(['pregame_safe_rate'], [
                'pregame_safe_rate' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1,
                    'description' => 'Historical stored signal assessments, not current safety revalidation. Null when no candidates or any included assessment is unknown.'],
            ], true),
            'MlbDailyPickCandidate' => $this->fixedObjectSchema(['game'], [
                'game' => ['oneOf' => [['$ref' => '#/components/schemas/MlbDailyPickGame'], ['type' => 'null']]],
            ], true),
            'MlbDailyPickGame' => $this->fixedObjectSchema(['id', 'game_date', 'starts_at'], [
                'id' => ['type' => 'integer'],
                'game_date' => ['type' => ['string', 'null'], 'format' => 'date-time',
                    'description' => 'Serialized stored calendar date. This is not a verified kickoff instant.'],
                'starts_at' => ['type' => ['string', 'null'], 'format' => 'date-time',
                    'description' => 'Offset-bearing observed kickoff bound to the current game. Unmarked or invalid legacy clocks remain null.'],
            ], true),

            'UserBetIndexResponse' => $this->fixedObjectSchema(['bets', 'summary', 'filters'], [
                'bets' => $openObject,
                'summary' => $openObject,
                'filters' => $openObject,
            ]),
            'UserBetCsvExport' => [
                'type' => 'string',
                'description' => 'CSV document containing the authenticated user betting ledger.',
            ],
        ];
    }

    private function cfbClosingMarketEvidenceSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableDate = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $quote = $this->fixedObjectSchema(['id', 'source', 'bookmaker_key', 'market_key', 'side', 'participant',
            'line', 'bookmaker_home_line', 'price', 'claimed_quote_hash', 'captured_at_stored', 'created_at_stored',
            'captured_at_interpreted', 'timestamp_policy'], [
                'id' => ['type' => 'integer'], 'source' => $nullableString, 'bookmaker_key' => $nullableString,
                'market_key' => $nullableString, 'side' => $nullableString, 'participant' => $nullableString,
                // Historical SQL decimal casts are copied as strings, without numeric reinterpretation.
                'line' => $nullableString, 'bookmaker_home_line' => $nullableString,
                'price' => ['type' => ['integer', 'null']], 'claimed_quote_hash' => $nullableString,
                'captured_at_stored' => $nullableString, 'created_at_stored' => $nullableString,
                'captured_at_interpreted' => $nullableDate, 'timestamp_policy' => ['type' => 'string'],
            ]);
        $output = $this->fixedObjectSchema(['closing_bookmaker_home_line', 'closing_home_margin', 'closing_captured_at',
            'closing_line_value_points', 'closing_line_value_bucket'], [
                'closing_bookmaker_home_line' => $nullableNumber, 'closing_home_margin' => $nullableNumber,
                'closing_captured_at' => $nullableDate, 'closing_line_value_points' => $nullableNumber,
                'closing_line_value_bucket' => $nullableString,
            ]);

        return $this->fixedObjectSchema(['type', 'phase', 'availability_status', 'reason_codes', 'source_integrity_status',
            'receipt_id', 'revision', 'entry_snapshot_id', 'observed_at', 'source_max_captured_at', 'timestamp_policy', 'output', 'source_quotes'], [
                'type' => ['type' => 'string', 'const' => 'closing_market_v1'],
                'phase' => ['type' => 'string', 'const' => 'postgame'],
                'availability_status' => ['type' => 'string', 'enum' => ['available', 'unavailable']],
                'reason_codes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'source_integrity_status' => ['type' => 'string', 'enum' => ['verified_capture_v2', 'legacy_unverified', 'mismatch']],
                'receipt_id' => ['type' => ['integer', 'null']], 'revision' => ['type' => ['integer', 'null']],
                'entry_snapshot_id' => ['type' => ['integer', 'null']], 'observed_at' => $nullableDate,
                'source_max_captured_at' => $nullableDate, 'timestamp_policy' => ['type' => 'string'],
                'output' => ['oneOf' => [$output, ['type' => 'null']]],
                'source_quotes' => ['type' => 'array', 'items' => $quote],
            ]);
    }

    /** @param list<string> $required @param array<string, mixed> $properties */
    private function requestObject(array $required, array $properties): array
    {
        return [
            'type' => 'object',
            ...($required === [] ? [] : ['required' => $required]),
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }

    /** @param list<string> $required */
    private function cbbBracketRequest(array $required, bool $picksNullable): array
    {
        $properties = [
            'season' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100],
            'name' => ['type' => ['string', 'null'], 'maxLength' => 255],
            'group_id' => ['type' => ['integer', 'null']],
            'picks' => ['type' => $picksNullable ? ['array', 'null'] : 'array', 'items' => ['type' => 'string', 'maxLength' => 255]],
        ];

        return $this->requestObject($required, $properties);
    }

    private function alertPreferenceRequest(bool $store): array
    {
        $required = $store
            ? ['enabled', 'notification_types', 'minimum_edge', 'time_window_start', 'time_window_end', 'digest_mode']
            : [];

        return $this->requestObject($required, [
            'enabled' => ['type' => 'boolean'],
            'notification_types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['email', 'sms', 'push', 'whatsapp']]],
            'minimum_edge' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'time_window_start' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'time_window_end' => ['type' => 'string', 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'digest_mode' => ['type' => 'string', 'enum' => ['realtime', 'daily_summary']],
            'digest_time' => ['type' => ['string', 'null'], 'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$'],
            'daily_digest_subscribed' => ['type' => 'boolean'],
            'phone_number' => ['type' => ['string', 'null'], 'maxLength' => 20],
            'sports' => false,
        ]);
    }

    private function cbbBracketSchema(): array
    {
        $schema = $this->fixedObjectSchema([
            'id', 'public_id', 'user_id', 'group_id', 'group', 'season', 'name', 'picks',
            'points_earned', 'max_points_remaining', 'correct_picks', 'incorrect_picks',
            'graded_through_round', 'results', 'is_locked', 'can_edit', 'lock_at',
            'submitted_at', 'created_at', 'updated_at',
        ], [
            'id' => ['type' => 'integer'],
            'public_id' => ['type' => 'string', 'format' => 'uuid'],
            'user_id' => ['type' => 'integer'],
            'group_id' => ['type' => ['integer', 'null']],
            'group' => [
                'type' => ['object', 'null'],
                'properties' => [
                    'id' => ['type' => ['integer', 'null']],
                    'public_id' => ['type' => ['string', 'null']],
                    'name' => ['type' => ['string', 'null']],
                ],
                'additionalProperties' => false,
            ],
            'season' => ['type' => 'integer'],
            'picks' => ['type' => 'array', 'items' => ['type' => 'string']],
            'results' => ['type' => 'array'],
            'is_locked' => ['type' => 'boolean'],
            'can_edit' => ['type' => 'boolean'],
        ]);

        // The resource deliberately omits this conditional relationship on the show route.
        $schema['required'] = array_values(array_diff($schema['required'], ['group']));

        return $schema;
    }

    private function sportInjurySchema(): array
    {
        $schema = $this->fixedObjectSchema([
            'id', 'player_id', 'team_id', 'status', 'detail', 'type', 'injury_date',
            'return_date', 'source_updated_at', 'is_active', 'updated_at', 'team_abbreviation',
            'player_name', 'position', 'depth_rank', 'is_starter', 'availability_probability',
            'impact_weight', 'expected_impact', 'impact_level', 'source', 'is_stale', 'source_clock_status',
        ], [
            'is_stale' => ['type' => ['boolean', 'null']],
            'source_clock_status' => ['type' => 'string', 'enum' => ['unverified_storage_clock']],
            'source_updated_at' => ['type' => ['string', 'null'], 'description' => 'Stored provider clock text with unverified timezone, not an observation instant.'],
        ]);

        // NFL enrichment appends the latter fields; other sports expose only the common row.
        $schema['required'] = [
            'id', 'player_id', 'team_id', 'status', 'detail', 'type', 'injury_date',
            'return_date', 'source_updated_at', 'is_active', 'updated_at', 'team_abbreviation',
            'player_name', 'is_stale', 'source_clock_status',
        ];

        return $schema;
    }

    private function injuryCollectionEnvelope(): array
    {
        $schema = $this->sportCollectionEnvelope('SportInjury');
        $schema['properties']['meta'] = ['allOf' => [
            ['$ref' => '#/components/schemas/SportMeta'],
            ['type' => 'object', 'required' => ['freshness'], 'properties' => ['freshness' => ['$ref' => '#/components/schemas/InjuryObservationFreshness']]],
        ]];

        return $schema;
    }

    private function teamAverageSchema(): array
    {
        $schema = $this->fixedObjectSchema(['team_id', 'games_played', 'season', 'season_type', 'sample_sizes'], [
            'team_id' => ['type' => 'integer'], 'games_played' => ['type' => 'integer', 'minimum' => 1],
            'season' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999], 'season_type' => ['type' => 'string'],
            'sample_sizes' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0]],
        ], true);
        $schema['properties']['shooting_totals'] = ['type' => 'object', 'additionalProperties' => $this->fixedObjectSchema(['made', 'attempted'], [
            'made' => ['type' => ['number', 'null']], 'attempted' => ['type' => ['number', 'null']],
        ])];

        return $schema;
    }

    private function teamAverageScopeSchema(): array
    {
        return $this->fixedObjectSchema(['season', 'season_type', 'selection', 'phase_selection', 'basis'], [
            'season' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 9999],
            'season_type' => ['type' => 'string'],
            'selection' => ['type' => 'string', 'enum' => ['explicit', 'latest_stored_completed']],
            'phase_selection' => ['type' => 'string', 'enum' => ['explicit', 'configured_default']],
            'basis' => ['type' => 'string', 'enum' => ['completed_game_team_stats']],
        ]);
    }

    private function teamAverageEnvelope(string $dataSchema): array
    {
        $schema = $this->sportCustomEnvelope($dataSchema);
        $schema['properties']['meta'] = ['allOf' => [
            ['$ref' => '#/components/schemas/SportMeta'],
            ['type' => 'object', 'required' => ['scope'], 'properties' => ['scope' => ['$ref' => '#/components/schemas/TeamStatAverageScope']]],
        ]];

        return $schema;
    }

    private function teamAverageNotFoundSchema(): array
    {
        return $this->fixedObjectSchema(['error', 'request_id', 'message', 'meta'], [
            'error' => $this->fixedObjectSchema(['code', 'message', 'request_id'], [
                'code' => ['type' => 'string', 'enum' => ['not_found']], 'message' => ['type' => 'string'], 'request_id' => ['type' => 'string'],
            ]),
            'request_id' => ['type' => 'string'], 'message' => ['type' => 'string'],
            'meta' => ['allOf' => [
                ['$ref' => '#/components/schemas/SportMeta'],
                ['type' => 'object', 'required' => ['scope', 'team_id'], 'properties' => ['scope' => ['$ref' => '#/components/schemas/TeamStatAverageScope'], 'team_id' => ['type' => 'integer']]],
            ]],
        ]);
    }

    private function injuryFreshnessSchema(): array
    {
        return $this->fixedObjectSchema(['last_observed_at', 'latest_source_updated_at', 'last_stored_at', 'age_hours', 'is_stale', 'stale_rows', 'unverified_rows', 'source_clock_status', 'coverage_status', 'max_age_hours'], [
            'last_observed_at' => ['type' => ['string', 'null']], 'latest_source_updated_at' => ['type' => ['string', 'null']],
            'last_stored_at' => ['type' => ['string', 'null'], 'description' => 'Raw local database mutation marker for included rows; timezone unverified. Not a provider sync or observation.'],
            'age_hours' => ['type' => ['integer', 'null']], 'is_stale' => ['type' => ['boolean', 'null']], 'stale_rows' => ['type' => ['integer', 'null']],
            'unverified_rows' => ['type' => 'integer', 'minimum' => 0],
            'source_clock_status' => ['type' => 'string', 'enum' => ['unverified_storage_clock', 'no_matching_observations']],
            'coverage_status' => ['type' => 'string', 'enum' => ['unverified']], 'max_age_hours' => ['type' => 'integer', 'enum' => [48]],
        ]);
    }

    /** @param list<string> $fields @param array<string, mixed> $overrides */
    private function fixedObjectSchema(array $fields, array $overrides = [], bool $additionalProperties = false): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field] = $overrides[$field] ?? new \stdClass;
        }

        return [
            'type' => 'object',
            'required' => $fields,
            'properties' => $properties,
            'additionalProperties' => $additionalProperties,
        ];
    }

    /** @param list<string> $required @param array<string, mixed> $properties */
    private function objectSchemaWithOptionalFields(array $required, array $properties): array
    {
        return ['type' => 'object', 'required' => $required, 'properties' => $properties, 'additionalProperties' => false];
    }

    private function itemEnvelope(string $schema, bool $withMeta, bool $nullable = false): array
    {
        $properties = [
            'data' => $nullable
                ? ['oneOf' => [['$ref' => "#/components/schemas/{$schema}"], ['type' => 'null']]]
                : ['$ref' => "#/components/schemas/{$schema}"],
        ];
        if ($withMeta) {
            $properties['meta'] = ['type' => 'object', 'additionalProperties' => true];
        }

        return ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties, 'additionalProperties' => false];
    }

    private function collectionEnvelope(string $schema, bool $withMeta): array
    {
        $properties = [
            'data' => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/{$schema}"]],
        ];
        if ($withMeta) {
            $properties['meta'] = ['type' => 'object', 'additionalProperties' => true];
        }

        return ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties, 'additionalProperties' => false];
    }

    private function sportItemEnvelope(string $schema, bool $nullable = false): array
    {
        $envelope = $this->itemEnvelope($schema, false, $nullable);
        $envelope['required'][] = 'meta';
        $envelope['properties']['meta'] = ['$ref' => '#/components/schemas/SportMeta'];

        return $envelope;
    }

    private function sportCollectionEnvelope(string $schema): array
    {
        $envelope = $this->collectionEnvelope($schema, false);
        $envelope['required'][] = 'meta';
        $envelope['properties']['meta'] = ['$ref' => '#/components/schemas/SportMeta'];

        return $envelope;
    }

    private function sportCustomEnvelope(string $dataSchema): array
    {
        return [
            'type' => 'object',
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['$ref' => "#/components/schemas/{$dataSchema}"],
                'meta' => ['$ref' => '#/components/schemas/SportMeta'],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        $sportGameProperties = $this->sportGameProperties();

        return [
            ...$this->contractSchemas(),
            'NativeDeviceSessionStoreRequest' => [
                'type' => 'object',
                'required' => ['device_name', 'platform'],
                'properties' => [
                    'device_name' => ['type' => 'string', 'maxLength' => 120],
                    'platform' => ['type' => 'string', 'enum' => ['ios', 'android']],
                    'device_identifier' => ['type' => ['string', 'null'], 'maxLength' => 255],
                ],
                'additionalProperties' => false,
            ],
            'NativeDeviceSessionRefreshRequest' => [
                'type' => 'object',
                'required' => ['refresh_token'],
                'properties' => [
                    'refresh_token' => ['type' => 'string', 'maxLength' => 512],
                ],
                'additionalProperties' => false,
            ],
            'NativePushRegistrationStoreRequest' => [
                'type' => 'object',
                'required' => ['provider', 'device_token'],
                'properties' => [
                    'provider' => ['type' => 'string', 'enum' => ['apns', 'fcm']],
                    'device_token' => ['type' => 'string', 'maxLength' => 4096],
                    'environment' => ['type' => ['string', 'null'], 'enum' => ['sandbox', 'production', null]],
                ],
                'additionalProperties' => false,
            ],
            'NativeDeviceTokenResponse' => [
                'type' => 'object',
                'required' => [
                    'token_type',
                    'access_token',
                    'refresh_token',
                    'access_token_expires_at',
                    'refresh_token_expires_at',
                    'device_session',
                ],
                'properties' => [
                    'token_type' => ['type' => 'string', 'const' => 'Bearer'],
                    'access_token' => ['type' => 'string'],
                    'refresh_token' => ['type' => 'string'],
                    'access_token_expires_at' => ['type' => 'string', 'format' => 'date-time'],
                    'refresh_token_expires_at' => ['type' => 'string', 'format' => 'date-time'],
                    'device_session' => [
                        'type' => 'object',
                        'required' => ['id', 'device_name', 'platform'],
                        'properties' => [
                            'id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                            'device_name' => ['type' => 'string'],
                            'platform' => ['type' => 'string', 'enum' => ['ios', 'android']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'NativePushRegistrationResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['device_session_id', 'provider', 'environment', 'last_registered_at'],
                        'properties' => [
                            'device_session_id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                            'provider' => ['type' => 'string', 'enum' => ['apns', 'fcm']],
                            'environment' => ['type' => ['string', 'null']],
                            'last_registered_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'UserBetStoreRequest' => [
                'type' => 'object',
                'required' => ['bet_amount', 'odds', 'bet_type'],
                'dependentRequired' => [
                    'prediction_id' => ['prediction_sport'],
                    'prediction_sport' => ['prediction_id'],
                ],
                'properties' => array_merge($this->userBetWriteProperties(), [
                    'prediction_id' => ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$'], ['type' => 'null']]],
                    'prediction_sport' => [
                        'type' => ['string', 'null'],
                        'enum' => ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb', null],
                    ],
                    'prediction_type' => false,
                ]),
                'additionalProperties' => true,
            ],
            'UserBetUpdateRequest' => [
                'type' => 'object',
                'properties' => array_merge($this->userBetWriteProperties(), [
                    'result' => ['type' => 'string', 'enum' => ['pending', 'won', 'lost', 'push']],
                    'profit_loss' => ['type' => ['number', 'null']],
                    'settled_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'prediction_type' => false,
                ]),
                'additionalProperties' => true,
            ],
            'UserBetResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/UserBet'],
                ],
                'additionalProperties' => false,
            ],
            'UserBet' => [
                'type' => 'object',
                'required' => ['id', 'prediction_id', 'prediction_sport', 'prediction_reference', 'bet_amount', 'odds', 'bet_type', 'result'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'user_id' => ['type' => 'integer'],
                    'prediction_id' => ['type' => ['integer', 'string', 'null']],
                    'prediction_sport' => [
                        'type' => ['string', 'null'],
                        'enum' => ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb', null],
                    ],
                    'prediction_reference' => [
                        'oneOf' => [
                            ['$ref' => '#/components/schemas/UserBetPredictionReference'],
                            ['type' => 'null'],
                        ],
                    ],
                    'bet_amount' => ['type' => 'string'],
                    'odds' => ['type' => 'string'],
                    'bet_type' => ['type' => 'string', 'enum' => ['spread', 'moneyline', 'total_over', 'total_under']],
                    'selection_side' => ['type' => ['string', 'null']],
                    'selection_label' => ['type' => ['string', 'null']],
                    'line' => ['type' => ['string', 'null']],
                    'result' => ['type' => 'string', 'enum' => ['pending', 'won', 'lost', 'push']],
                    'profit_loss' => ['type' => ['string', 'null']],
                    'notes' => ['type' => ['string', 'null']],
                    'placed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'settled_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
                'additionalProperties' => false,
            ],
            'UserBetPredictionReference' => [
                'type' => 'object',
                'required' => ['sport', 'id', 'event_id'],
                'properties' => [
                    'sport' => ['type' => 'string', 'enum' => ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb']],
                    'id' => ['type' => ['integer', 'string']],
                    'event_id' => ['type' => ['string', 'null']],
                ],
                'additionalProperties' => false,
            ],
            'ApiErrorResponse' => [
                'type' => 'object',
                'required' => ['error', 'request_id', 'message'],
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message', 'request_id'],
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'request_id' => ['type' => 'string'],
                            'fields' => [
                                'type' => 'object',
                                'additionalProperties' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                    'request_id' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'additionalProperties' => false,
            ],
            'SportGameCollectionResponse' => [
                'type' => 'object',
                'required' => ['data', 'meta'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/SportGame'],
                    ],
                    'meta' => ['$ref' => '#/components/schemas/SportMeta'],
                ],
                'additionalProperties' => false,
            ],
            'SportGameResponse' => [
                'type' => 'object',
                'required' => ['data', 'meta'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/SportGame'],
                    'meta' => ['$ref' => '#/components/schemas/SportMeta'],
                ],
                'additionalProperties' => false,
            ],
            'SportGame' => [
                'type' => 'object',
                'required' => array_keys($sportGameProperties),
                'properties' => $sportGameProperties,
                'additionalProperties' => false,
            ],
            'SportMeta' => [
                'type' => 'object',
                'properties' => [
                    'version' => ['type' => 'string', 'example' => 'v2'],
                    'sport' => ['type' => 'string', 'example' => 'mlb'],
                    'contract' => ['type' => 'string', 'example' => 'sports.predictions.index'],
                    'filters' => ['type' => 'object', 'additionalProperties' => true],
                    'pagination' => ['type' => 'object', 'additionalProperties' => true],
                    'tier' => ['type' => 'object', 'additionalProperties' => true],
                    'freshness' => ['type' => 'object', 'additionalProperties' => true],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'additionalProperties' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sportGameProperties(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableInteger = ['type' => ['integer', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableObject = ['type' => ['object', 'null'], 'additionalProperties' => true];
        $nullableArray = ['type' => ['array', 'null'], 'items' => new \stdClass];
        $integerStringOrNull = ['type' => ['integer', 'string', 'null']];

        return [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'sport_event_id' => [
                'type' => ['string', 'null'],
                'description' => 'Stable canonical sport-event ULID. Null only while a legacy row awaits identity backfill.',
                'minLength' => 26,
                'maxLength' => 26,
                'pattern' => '^[0-9A-HJKMNP-TV-Z]{26}$',
            ],
            'sport' => ['type' => 'string', 'enum' => ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb']],
            'espn_id' => $nullableString,
            'espn_event_id' => $nullableString,
            'espn_uid' => $nullableString,
            'season' => $nullableInteger,
            'season_type' => $integerStringOrNull,
            'week' => $nullableInteger,
            'postseason_round' => $integerStringOrNull,
            'name' => $nullableString,
            'short_name' => $nullableString,
            'game_date' => ['type' => ['string', 'null'], 'format' => 'date'],
            'game_time' => $nullableString,
            'starts_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Verified kickoff instant; null when the stored clock is unavailable or unverified.'],
            'start_time_provenance' => ['type' => ['string', 'null'], 'enum' => ['observed_offset_timestamp_v1', 'legacy_time_unverified', 'observed_start_invalid', 'provider_clock_known_v1', 'legacy_clock_unverified', 'clock_unconfirmed_or_invalid', null]],
            'venue' => $nullableString,
            'venue_name' => $nullableString,
            'venue_city' => $nullableString,
            'venue_state' => $nullableString,
            'attendance' => $nullableInteger,
            'status' => $nullableString,
            'period' => $nullableInteger,
            'clock' => $nullableString,
            'game_clock' => $nullableString,
            'home_team_id' => $nullableInteger,
            'away_team_id' => $nullableInteger,
            'home_score' => $nullableNumber,
            'away_score' => $nullableNumber,
            'home_linescores' => $nullableArray,
            'away_linescores' => $nullableArray,
            'broadcast_networks' => $nullableArray,
            'inning' => $nullableInteger,
            'inning_half' => $nullableString,
            'balls' => $nullableInteger,
            'strikes' => $nullableInteger,
            'outs' => $nullableInteger,
            'probable_home_pitcher_espn_id' => $nullableString,
            'probable_away_pitcher_espn_id' => $nullableString,
            'actual_home_pitcher_espn_id' => $nullableString,
            'actual_away_pitcher_espn_id' => $nullableString,
            'projected_home_pitcher_espn_id' => $nullableString,
            'projected_away_pitcher_espn_id' => $nullableString,
            'home_starting_pitcher_source' => $nullableString,
            'away_starting_pitcher_source' => $nullableString,
            'home_starting_pitcher_confidence' => $nullableNumber,
            'away_starting_pitcher_confidence' => $nullableNumber,
            'home_starting_pitcher_candidates' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'away_starting_pitcher_candidates' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'home_expected_starting_pitcher_rating' => $nullableNumber,
            'away_expected_starting_pitcher_rating' => $nullableNumber,
            'home_starting_pitcher_uncertainty' => $nullableNumber,
            'away_starting_pitcher_uncertainty' => $nullableNumber,
            'pitcher_projection_metadata' => $nullableObject,
            'pitcher_projection_generated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'starting_pitcher_confirmation_metadata' => $nullableObject,
            'starting_pitchers_confirmed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'is_ncaa_tournament' => ['type' => 'boolean'],
            'tournament_id' => $integerStringOrNull,
            'tournament_note' => $nullableString,
            'tournament_round' => $integerStringOrNull,
            'tournament_region' => $nullableString,
            'home_seed' => $nullableInteger,
            'away_seed' => $nullableInteger,
            'play_in_target_seed' => $nullableInteger,
            'matchup_context' => $nullableObject,
            'home_team' => $nullableObject,
            'away_team' => $nullableObject,
            'home_starting_pitcher' => $nullableObject,
            'away_starting_pitcher' => $nullableObject,
            'home_starting_pitcher_forecast' => $nullableObject,
            'away_starting_pitcher_forecast' => $nullableObject,
            'has_prediction' => ['type' => 'boolean'],
            'completed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'updated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tags(): array
    {
        return collect($this->v2Routes())
            ->map(fn (Route $route): string => $this->tagForRoute($route))
            ->unique()
            ->sort()
            ->map(fn (string $tag): array => ['name' => $tag])
            ->values()
            ->all();
    }

    private function tagForRoute(Route $route): string
    {
        $name = $route->getName() ?? '';

        if (str_contains($name, 'admin.')) {
            return 'Admin';
        }

        if (str_contains($name, 'auth.')) {
            return 'Auth';
        }

        if (str_contains($name, 'user-bets')) {
            return 'User Bets';
        }

        if (str_contains($name, 'cbb-brackets')) {
            return 'CBB Brackets';
        }

        if (str_contains($name, 'groups')) {
            return 'Groups';
        }

        if (str_contains($name, 'alert-preferences')) {
            return 'Alert Preferences';
        }

        if (str_contains($name, 'live-scoreboard')) {
            return 'Live Scoreboard';
        }

        if (str_contains($name, 'sports.') && ! in_array($name, ['v2.sports.index', 'v2.sports.show'], true)) {
            return 'Sport Data';
        }

        return 'Sports';
    }

    private function requiresSanctum(Route $route): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_contains($middleware, 'auth:sanctum')
                || $middleware === 'v2.auth');
    }

    private function requiresDeveloperCredential(Route $route): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_contains($middleware, 'auth:developer-api'));
    }

    private function summary(string $routeName, string $method): string
    {
        $name = Str::of($routeName)
            ->replace('v2.', '')
            ->replace(['.', '-'], [' ', ' '])
            ->headline()
            ->toString();

        return trim($method.' '.$name);
    }

    private function outputPath(): string
    {
        $path = (string) $this->option('output');

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
