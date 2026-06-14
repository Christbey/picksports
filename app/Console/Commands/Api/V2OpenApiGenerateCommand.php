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
                'description' => 'Generated route-level OpenAPI artifact for the PickSports Laravel API v2 surface. Response schemas are intentionally generic; contract tests and API resources remain the detailed payload authority.',
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
                        'bearerFormat' => 'Sanctum token',
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
            'responses' => $this->responsesFor($route, $method),
        ];

        if ($this->requiresSanctum($route)) {
            $operation['security'] = [['sanctumBearer' => []]];
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => true,
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
            ->map(fn (string $parameter): array => [
                'name' => $parameter,
                'in' => 'path',
                'required' => true,
                'schema' => $this->pathParameterSchema($parameter),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function pathParameterSchema(string $parameter): array
    {
        if ($parameter === 'sport') {
            return [
                'type' => 'string',
                'enum' => ['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb'],
            ];
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
    private function responsesFor(Route $route, string $method): array
    {
        $responses = [
            '200' => [
                'description' => 'Successful response.',
                'content' => [
                    'application/json' => [
                        'schema' => $this->successSchema($route),
                    ],
                ],
            ],
        ];

        if ($this->requiresSanctum($route)) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }

        if ($route->parameterNames() !== []) {
            $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        if ($this->queryParameterNames($route->getName() ?? '') !== [] || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $responses['422'] = ['$ref' => '#/components/responses/ValidationError'];
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedResponses(): array
    {
        return [
            'Unauthenticated' => [
                'description' => 'Unauthenticated.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/GenericJsonResponse'],
                    ],
                ],
            ],
            'Forbidden' => [
                'description' => 'Authenticated user is not allowed to access this resource.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/GenericJsonResponse'],
                    ],
                ],
            ],
            'NotFound' => [
                'description' => 'Resource not found or sport slug is unsupported.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/GenericJsonResponse'],
                    ],
                ],
            ],
            'ValidationError' => [
                'description' => 'Validation error.',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/GenericJsonResponse'],
                    ],
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

        if (str_contains($name, 'sports.') && ! str_contains($name, 'sports.index') && ! str_contains($name, 'sports.show')) {
            return [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/SportCollectionResponse'],
                    ['$ref' => '#/components/schemas/SportItemResponse'],
                    ['$ref' => '#/components/schemas/SportCustomResponse'],
                ],
            ];
        }

        return ['$ref' => '#/components/schemas/GenericJsonResponse'];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return [
            'GenericJsonResponse' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
            'SportCollectionResponse' => [
                'type' => 'object',
                'required' => ['data', 'meta'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                    'meta' => ['$ref' => '#/components/schemas/SportMeta'],
                ],
                'additionalProperties' => true,
            ],
            'SportItemResponse' => [
                'type' => 'object',
                'required' => ['data', 'meta'],
                'properties' => [
                    'data' => ['type' => 'object', 'additionalProperties' => true],
                    'meta' => ['$ref' => '#/components/schemas/SportMeta'],
                ],
                'additionalProperties' => true,
            ],
            'SportCustomResponse' => [
                'type' => 'object',
                'required' => ['data', 'meta'],
                'properties' => [
                    'data' => ['additionalProperties' => true],
                    'meta' => ['$ref' => '#/components/schemas/SportMeta'],
                ],
                'additionalProperties' => true,
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
            ->contains(fn (string $middleware): bool => str_contains($middleware, 'auth:sanctum'));
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
