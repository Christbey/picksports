<?php

namespace App\Http\Controllers;

use App\Http\Resources\BettingRecommendationResource;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Support\InjuryImpactScorer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class PublicSportPageController extends Controller
{
    public function __construct(
        private readonly PlayerPropAnalyzer $playerPropAnalyzer,
        private readonly InjuryImpactScorer $injuryImpactScorer,
    ) {}

    public function __invoke(Request $request, string $sport): Response
    {
        $sport = strtolower($sport);
        $definition = (array) data_get(config('sports.domains'), $sport);

        if ($definition === []) {
            abort(404);
        }

        $namespace = (string) ($definition['namespace'] ?? '');
        if ($namespace === '') {
            abort(404);
        }

        $gameModel = $this->resolveModelClass($namespace, 'Game');
        $latestSeason = $this->latestSeason($gameModel);
        $injuries = $this->injurySummaries($sport);
        $topTeams = $this->topTeams($sport, $namespace, $latestSeason);
        $topPlayers = $this->topPlayers($sport, $namespace, $latestSeason);
        $featuredPredictions = $this->featuredPredictions($sport, $namespace);
        $featuredProps = $this->featuredProps($sport, $definition);
        $conferencePlayoffTeams = $sport === 'nba' ? $this->nbaConferencePlayoffTeams() : null;

        return Inertia::render('PublicSport', [
            'sport' => $sport,
            'sportLabel' => $this->sportLabel($sport),
            'latestSeason' => $latestSeason,
            'hasPlayerProps' => (bool) data_get($definition, 'web.player_props', false),
            'conferencePlayoffTeams' => $conferencePlayoffTeams,
            'injuries' => $injuries,
            'topTeams' => $topTeams,
            'topPlayers' => $topPlayers,
            'featuredPredictions' => $featuredPredictions,
            'featuredProps' => $featuredProps,
            'links' => [
                'predictions' => "/{$sport}/predictions",
                'injuries' => "/{$sport}/injuries",
                'teamMetrics' => "/{$sport}/team-metrics",
                'playerStats' => "/{$sport}/player-stats",
                'playerProps' => "/{$sport}/player-props",
            ],
            'summary' => [
                'topInjuriesCount' => count($injuries['top']),
                'recentInjuriesCount' => count($injuries['recent']),
                'topTeamsCount' => count($topTeams),
                'topPlayersCount' => count($topPlayers),
                'predictionsCount' => count($featuredPredictions),
                'propsCount' => count($featuredProps),
            ],
        ]);
    }

    /**
     * @return array{top: array<int, array<string, mixed>>, recent: array<int, array<string, mixed>>}
     */
    private function injurySummaries(string $sport): array
    {
        $injuryTable = "{$sport}_player_injuries";
        $playerTable = "{$sport}_players";
        $teamTable = "{$sport}_teams";

        if (! Schema::hasTable($injuryTable) || ! Schema::hasTable($playerTable) || ! Schema::hasTable($teamTable)) {
            return ['top' => [], 'recent' => []];
        }

        $playerNameColumn = $this->resolvePlayerNameColumn(Schema::getColumnListing($playerTable));

        $rows = DB::table("{$injuryTable} as injuries")
            ->join("{$teamTable} as teams", 'teams.id', '=', 'injuries.team_id')
            ->leftJoin("{$playerTable} as players", 'players.id', '=', 'injuries.player_id')
            ->select([
                'injuries.id',
                'injuries.player_id',
                'injuries.team_id',
                'injuries.status',
                'injuries.detail',
                'injuries.type',
                'injuries.injury_date',
                'injuries.return_date',
                'injuries.source_updated_at',
                'injuries.updated_at',
                'teams.abbreviation as team_abbreviation',
                DB::raw($playerNameColumn.' as player_name'),
            ])
            ->where('injuries.is_active', true)
            ->get()
            ->map(function (object $row) use ($sport): array {
                $impact = $this->injuryImpactScorer->describe(
                    $sport,
                    (int) ($row->player_id ?? 0),
                    (string) ($row->status ?? '')
                );

                return [
                    'id' => (int) $row->id,
                    'player_name' => $row->player_name ?: 'Unknown Player',
                    'team_abbreviation' => $row->team_abbreviation,
                    'status' => $row->status,
                    'detail' => $row->detail,
                    'type' => $row->type,
                    'injury_date' => $row->injury_date,
                    'return_date' => $row->return_date,
                    'source_updated_at' => $this->normalizeDateTime($row->source_updated_at),
                    'updated_at' => $this->normalizeDateTime($row->updated_at),
                    'impact_score' => $impact['score'],
                    'impact_label' => $impact['label'],
                ];
            });

        return [
            'top' => $rows
                ->sortByDesc(fn (array $injury) => sprintf(
                    '%08.1f|%s',
                    (float) ($injury['impact_score'] ?? 0),
                    (string) ($injury['updated_at'] ?? '')
                ))
                ->take(5)
                ->values()
                ->all(),
            'recent' => $rows
                ->sortByDesc(fn (array $injury) => $injury['source_updated_at'] ?? $injury['updated_at'] ?? '')
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topTeams(string $sport, string $namespace, ?int $latestSeason): array
    {
        $teamMetricModel = $this->resolveModelClass($namespace, 'TeamMetric');
        if ($teamMetricModel === null) {
            return [];
        }

        /** @var Model $instance */
        $instance = new $teamMetricModel;
        $table = $instance->getTable();
        $orderColumn = $this->teamMetricOrderColumn($sport, $table);

        if ($orderColumn === null) {
            return [];
        }

        $query = $teamMetricModel::query()->with('team')->orderByDesc($orderColumn);

        if ($latestSeason !== null && Schema::hasColumn($table, 'season')) {
            $query->where('season', $latestSeason);
        }

        if (Schema::hasColumn($table, 'wins')) {
            $query->orderByDesc('wins');
        }

        return $query
            ->limit(25)
            ->get()
            ->values()
            ->map(function ($metric, int $index) use ($orderColumn): array {
                $team = $metric->team;

                return [
                    'rank' => $index + 1,
                    'team_id' => $team?->id,
                    'name' => $this->teamDisplayName($team),
                    'abbreviation' => $team?->abbreviation,
                    'logo' => $team?->logo ?? $team?->logo_url ?? null,
                    'record' => $this->teamRecord($metric),
                    'primary_metric_label' => $this->metricLabel($orderColumn),
                    'primary_metric_value' => $metric->{$orderColumn},
                    'net_rating' => $metric->net_rating ?? null,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topPlayers(string $sport, string $namespace, ?int $latestSeason): array
    {
        $controllerClass = "App\\Http\\Controllers\\Api\\{$namespace}\\PlayerStatController";
        if (! class_exists($controllerClass)) {
            return [];
        }

        $request = Request::create('/', 'GET', array_filter([
            'min_games' => in_array($sport, ['nfl', 'cfb'], true) ? 2 : 5,
            'season' => $latestSeason,
        ], fn ($value) => $value !== null));

        $response = app($controllerClass)->leaderboard($request);
        $payload = $this->extractResourcePayload($response, $request);
        $rows = collect($payload['data'] ?? []);

        return $rows
            ->sortByDesc(fn (array $row) => (float) (data_get($row, 'points_per_game') ?? 0))
            ->values()
            ->reduce(function (Collection $carry, array $row) {
                if ($carry->count() >= 10) {
                    return $carry;
                }

                $teamAbbreviation = (string) data_get($row, 'player.team.abbreviation', '');
                $sameTeamCount = $teamAbbreviation === ''
                    ? 0
                    : $carry->filter(fn (array $entry) => ($entry['team_abbreviation'] ?? null) === $teamAbbreviation)->count();

                // Public landing pages should show star players across the sport, not a single roster dump.
                if ($teamAbbreviation !== '' && $sameTeamCount >= 2) {
                    return $carry;
                }

                return $carry->push([
                    'player_id' => $row['player']['id'] ?? $row['player_id'] ?? null,
                    'name' => $row['player']['full_name'] ?? $row['player']['name'] ?? 'Unknown Player',
                    'team_abbreviation' => data_get($row, 'player.team.abbreviation'),
                    'position' => data_get($row, 'player.position'),
                    'headshot' => data_get($row, 'player.headshot_url') ?? data_get($row, 'player.headshot'),
                    'games_played' => $row['games_played'] ?? null,
                    'headline_stat_label' => 'PPG',
                    'headline_stat_value' => data_get($row, 'points_per_game'),
                ]);
            }, collect())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function featuredPredictions(string $sport, string $namespace): array
    {
        $predictionModel = $this->resolveModelClass($namespace, 'Prediction');
        $gameModel = $this->resolveModelClass($namespace, 'Game');

        if ($predictionModel === null || $gameModel === null) {
            return [];
        }

        /** @var Model $gameInstance */
        $gameInstance = new $gameModel;
        /** @var Model $predictionInstance */
        $predictionInstance = new $predictionModel;
        $gameTable = $gameInstance->getTable();
        $predictionTable = $predictionInstance->getTable();
        $scheduledStatus = config("{$sport}.statuses.scheduled");
        $inProgressStatus = config("{$sport}.statuses.in_progress");

        $upcoming = $predictionModel::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->whereHas('game', function ($query) use ($scheduledStatus, $inProgressStatus) {
                $query->whereDate('game_date', '>=', now()->subDay()->toDateString())
                    ->whereIn('status', array_values(array_filter([$scheduledStatus, $inProgressStatus])));
            })
            ->join($gameTable, "{$gameTable}.id", '=', "{$predictionTable}.game_id")
            ->orderBy("{$gameTable}.game_date")
            ->select("{$predictionTable}.*")
            ->limit(5)
            ->get();

        $predictions = $upcoming->isNotEmpty()
            ? $upcoming
            : $predictionModel::query()
                ->with(['game.homeTeam', 'game.awayTeam'])
                ->latest()
                ->limit(5)
                ->get();

        return $predictions
            ->map(function ($prediction): array {
                $game = $prediction->game;
                $homeTeam = $game?->homeTeam;
                $awayTeam = $game?->awayTeam;
                $homeWinProbability = $this->homeWinProbability($prediction);
                $pick = $homeWinProbability >= 0.5 ? $this->teamDisplayName($homeTeam) : $this->teamDisplayName($awayTeam);

                return [
                    'id' => $prediction->id,
                    'game_id' => $game?->id,
                    'matchup' => trim($this->teamDisplayName($awayTeam).' at '.$this->teamDisplayName($homeTeam)),
                    'game_date' => $game?->game_date?->toIso8601String() ?? $game?->game_date,
                    'status' => $game?->status,
                    'pick' => $pick,
                    'home_team_abbreviation' => $homeTeam?->abbreviation,
                    'away_team_abbreviation' => $awayTeam?->abbreviation,
                    'predicted_spread' => $prediction->predicted_spread,
                    'predicted_total' => $prediction->predicted_total,
                    'confidence_score' => $prediction->confidence_score,
                    'home_win_probability' => round($homeWinProbability * 100, 1),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function featuredProps(string $sport, array $definition): array
    {
        if (! (bool) data_get($definition, 'web.player_props', false)) {
            return [];
        }

        $sportCode = match ($sport) {
            'nba' => 'NBA',
            'nfl' => 'NFL',
            'cbb' => 'CBB',
            'mlb' => 'MLB',
            default => null,
        };

        if ($sportCode === null) {
            return [];
        }

        $date = $this->defaultPropsDate($sportCode);
        $recommendations = $this->playerPropAnalyzer
            ->analyzeProps(sport: $sportCode, minGames: 3, dateFilter: $date)
            ->take(5);

        return BettingRecommendationResource::collection($recommendations)->resolve();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>|null
     */
    private function nbaConferencePlayoffTeams(): ?array
    {
        $forecastModel = $this->resolveModelClass('NBA', 'PlayoffForecast');
        if ($forecastModel === null) {
            return null;
        }

        $season = $forecastModel::query()->max('season');
        if ($season === null) {
            return [
                'east' => [],
                'west' => [],
            ];
        }

        $slots = (int) config('nba.playoff_forecast.playoff_teams_per_conference', 8);

        $rows = $forecastModel::query()
            ->with('team')
            ->where('season', $season)
            ->whereNotNull('conference_rank')
            ->orderBy('conference_rank')
            ->get()
            ->map(fn ($forecast): array => [
                'team_id' => $forecast->team_id,
                'seed' => $forecast->conference_rank,
                'projected_seed' => $forecast->projected_seed,
                'name' => $this->teamDisplayName($forecast->team),
                'abbreviation' => $forecast->team?->abbreviation,
                'logo' => $forecast->team?->logo ?? $forecast->team?->logo_url ?? null,
                'conference' => strtolower((string) ($forecast->conference ?? $forecast->team?->conference ?? '')),
                'playoff_make_probability' => $forecast->playoff_make_probability,
                'direct_playoff_probability' => $forecast->direct_playoff_probability,
            ]);

        return [
            'east' => $rows
                ->filter(fn (array $row) => str_starts_with($row['conference'], 'east'))
                ->take($slots)
                ->values()
                ->all(),
            'west' => $rows
                ->filter(fn (array $row) => str_starts_with($row['conference'], 'west'))
                ->take($slots)
                ->values()
                ->all(),
        ];
    }

    private function latestSeason(?string $gameModel): ?int
    {
        if ($gameModel === null) {
            return null;
        }

        /** @var Model $instance */
        $instance = new $gameModel;
        $table = $instance->getTable();

        if (! Schema::hasColumn($table, 'season')) {
            return null;
        }

        $season = $gameModel::query()->max('season');

        return $season !== null ? (int) $season : null;
    }

    private function resolveModelClass(string $namespace, string $model): ?string
    {
        $class = "App\\Models\\{$namespace}\\{$model}";

        return class_exists($class) ? $class : null;
    }

    private function resolvePlayerNameColumn(array $columns): string
    {
        foreach (['full_name', 'display_name', 'name', 'short_name'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return "NULLIF(players.{$candidate}, '')";
            }
        }

        if (in_array('first_name', $columns, true) || in_array('last_name', $columns, true)) {
            return "NULLIF(TRIM(CONCAT(COALESCE(players.first_name, ''), ' ', COALESCE(players.last_name, ''))), '')";
        }

        return "'Unknown Player'";
    }

    private function teamMetricOrderColumn(string $sport, string $table): ?string
    {
        $preferred = match ($sport) {
            'cfb' => ['power_rating', 'cfp_rating', 'resume_rating', 'net_rating'],
            'cbb', 'wcbb' => ['adj_net_rating', 'net_rating'],
            default => ['net_rating'],
        };

        foreach ($preferred as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function metricLabel(string $column): string
    {
        return match ($column) {
            'adj_net_rating' => 'Adj Net',
            'power_rating' => 'Power',
            'cfp_rating' => 'CFP',
            'resume_rating' => 'Resume',
            'net_rating' => 'Net Rating',
            default => str_replace('_', ' ', ucwords($column, '_')),
        };
    }

    private function teamDisplayName(mixed $team): string
    {
        if ($team === null) {
            return 'Unknown Team';
        }

        foreach (['display_name', 'short_display_name', 'school'] as $field) {
            $value = data_get($team, $field);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $location = trim((string) data_get($team, 'location', ''));
        $name = trim((string) data_get($team, 'name', ''));

        return trim($location.' '.$name) ?: ((string) data_get($team, 'abbreviation', 'Unknown Team'));
    }

    private function teamRecord(mixed $metric): ?string
    {
        $wins = data_get($metric, 'wins');
        $losses = data_get($metric, 'losses');

        if ($wins === null && $losses === null) {
            return null;
        }

        return "{$wins}-{$losses}";
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function extractResourcePayload(mixed $response, Request $request): array
    {
        if ($response instanceof JsonResponse) {
            return $response->getData(true);
        }

        if ($response instanceof AnonymousResourceCollection) {
            return $response->response($request)->getData(true);
        }

        return [];
    }

    private function homeWinProbability(mixed $prediction): float
    {
        $winProbability = data_get($prediction, 'win_probability');

        if ($winProbability === null) {
            return 0.5;
        }

        $value = (float) $winProbability;

        return $value > 1 ? ($value / 100) : $value;
    }

    private function defaultPropsDate(string $sportCode): ?string
    {
        $dates = $this->playerPropAnalyzer->getAvailableDatesForSport($sportCode)->pluck('value')->filter()->values();
        if ($dates->isEmpty()) {
            return null;
        }

        $today = now()->toDateString();
        if ($dates->contains($today)) {
            return $today;
        }

        $futureDate = $dates->first(fn ($date) => is_string($date) && $date >= $today);

        return is_string($futureDate) ? $futureDate : (string) $dates->last();
    }

    private function sportLabel(string $sport): string
    {
        return match ($sport) {
            'cfb' => 'College Football',
            'cbb' => 'College Basketball',
            'wcbb' => 'Women\'s College Basketball',
            default => strtoupper($sport),
        };
    }
}
