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
        'games' => ['status', 'season', 'from_date', 'to_date', 'per_page'],
        'predictions' => ['season', 'season_type', 'week', 'from_date', 'to_date', 'status', 'team_id', 'game_id', 'include', 'market', 'per_page'],
        'teams' => ['conference', 'division', 'league', 'search', 'per_page'],
        'players' => ['team_id', 'position', 'status', 'search', 'per_page'],
        'metrics.teams' => ['season', 'season_type', 'team_id', 'per_page'],
        'stats' => ['season', 'season_type', 'week', 'from_date', 'to_date', 'game_id', 'team_id', 'player_id', 'stat_type', 'team_type', 'per_page'],
        'season-averages' => ['season', 'season_type', 'team_id', 'per_page'],
        'trends' => ['games', 'season', 'season_type', 'before_date'],
        'player-props' => ['date', 'from_date', 'to_date', 'game_id', 'player_id', 'market', 'bookmaker', 'recommended_side', 'per_page'],
        'futures' => ['season', 'market_key', 'bookmaker', 'team_id', 'player_id', 'event_id', 'outcome_name', 'per_page'],
        'forecasts' => ['season', 'as_of_date'],
        'signals' => ['season', 'as_of_date'],
        'injuries' => ['active', 'team_id', 'status'],
        'leaderboards.players' => ['season', 'season_type', 'stat_type', 'min_games'],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $queryParameterSchemas = [
        'active' => ['type' => 'boolean'],
        'as_of_date' => ['type' => 'string', 'format' => 'date'],
        'before_date' => ['type' => 'string', 'format' => 'date'],
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
                'enum' => ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb'],
            ];
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
                'schema' => $this->queryParameterSchemas[$parameter] ?? ['type' => 'string'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function queryParameterNames(string $routeName): array
    {
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

        foreach ($this->queryParametersByFamily as $family => $parameters) {
            if (str_contains($routeName, '.'.$family.'.')) {
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

        if ($this->queryParameterNames($route->getName() ?? '') !== [] || $this->requestBodyRequired($route) !== null) {
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

        if ($this->usesNamedThrottle($route)) {
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
            'v2.auth.passkeys.createOptions',
            'v2.user-bets.update',
            'v2.cbb-brackets.update',
            'v2.groups.update',
            'v2.alert-preferences.update' => false,
            default => null,
        };
    }

    private function usesNamedThrottle(Route $route): bool
    {
        return collect($route->gatherMiddleware())->contains(
            fn (mixed $middleware): bool => str_starts_with((string) $middleware, 'throttle:api-v2-'),
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
            'v2.alert-preferences.show',
            'v2.alert-preferences.store',
            'v2.alert-preferences.update' => 'AlertPreferenceResponse',
            'v2.auth.login', 'v2.auth.passkeys.verify' => 'TokenAuthResponse',
            'v2.auth.me' => 'AuthUserResponse',
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
            'throwing_hand', 'headshot_url', 'team', 'created_at', 'updated_at',
        ], [
            'id' => $nullableInteger,
            'team_id' => $nullableInteger,
            'sport' => ['type' => 'string'],
            'team' => $nullableOpenObject,
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
        $sportPrediction = $this->fixedObjectSchema([
            'id', 'sport', 'game_id', 'home_team_id', 'away_team_id', 'game', 'status',
            'pick', 'projection', 'home_win_probability', 'away_win_probability',
            'win_probability', 'predicted_spread', 'predicted_total', 'confidence_score',
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
            'TokenAuthResponse' => $this->fixedObjectSchema(['token_type', 'access_token', 'user'], [
                'token_type' => ['type' => 'string', 'const' => 'Bearer'],
                'access_token' => ['type' => 'string'],
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
            'SportInjuryCollectionResponse' => $this->sportCollectionEnvelope('SportInjury'),
            'SportAvailableDatesResponse' => $this->sportCustomEnvelope('SportAvailableDates'),
            'SportAvailableDates' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date']],
            'SportAvailableSeasonsResponse' => $this->sportCustomEnvelope('SportAvailableSeasons'),
            'SportAvailableSeasons' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'SportPlayerLeaderboardResponse' => $this->sportCustomEnvelope('SportPlayerLeaderboardRows'),
            'SportPlayerLeaderboardRows' => ['type' => 'array', 'items' => $openObject],
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
            'SportGamePageResponse' => $this->sportCustomEnvelope('SportGamePageData'),
            'SportGamePageData' => $this->fixedObjectSchema([
                'game', 'prediction', 'recent_games', 'metrics', 'depth_charts_available',
            ], [
                'game' => ['$ref' => '#/components/schemas/SportGame'],
                'prediction' => ['oneOf' => [['$ref' => '#/components/schemas/SportPrediction'], ['type' => 'null']]],
                'recent_games' => $openObject,
                'metrics' => $openObject,
                'depth_charts_available' => ['type' => 'boolean'],
            ]),
            'SportPlayerPropBoardResponse' => $this->sportCustomEnvelope('SportPlayerPropBoardData'),
            'SportPlayerPropBoardData' => $openObject,
            'SportTeamStatAverageCollectionResponse' => $this->sportCustomEnvelope('SportTeamStatAverageRows'),
            'SportTeamStatAverageRows' => ['type' => 'array', 'items' => $openObject],
            'SportTeamStatAverageResponse' => $this->sportCustomEnvelope('SportTeamStatAverage'),
            'SportTeamStatAverage' => $openObject,
            'MlbDailyPicksResponse' => $this->sportCustomEnvelope('MlbDailyPicksData'),
            'MlbDailyPicksData' => $openObject,

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
            'impact_weight', 'expected_impact', 'impact_level', 'source', 'is_stale',
        ]);

        // NFL enrichment appends the latter fields; other sports expose only the common row.
        $schema['required'] = [
            'id', 'player_id', 'team_id', 'status', 'detail', 'type', 'injury_date',
            'return_date', 'source_updated_at', 'is_active', 'updated_at', 'team_abbreviation',
            'player_name',
        ];

        return $schema;
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
                    'prediction_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
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
                    'prediction_id' => ['type' => ['integer', 'null']],
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
                    'id' => ['type' => 'integer'],
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
