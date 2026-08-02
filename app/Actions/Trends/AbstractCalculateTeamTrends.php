<?php

namespace App\Actions\Trends;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

abstract class AbstractCalculateTeamTrends
{
    protected const SPORT_KEY = '';

    protected const GAME_MODEL = '';

    /**
     * @var array<int, class-string>
     */
    protected const COLLECTORS = [];

    /**
     * @var array<int, string>
     */
    protected const GAME_RELATIONS = ['homeTeam', 'awayTeam', 'teamStats', 'prediction'];

    protected const USES_ANALYTICS_SEASON_TYPES = false;

    protected const ANALYTICS_SEASON_TYPES_CONFIG_KEY = '';

    protected const DEFAULT_GAME_COUNT = 20;

    /**
     * @return array{trends: array<string, array<int, string>>, locked: array<string, string>}
     */
    public function execute(
        object $team,
        int $gameCount = 0,
        ?int $season = null,
        ?string $seasonType = null,
        ?string $beforeDate = null,
        string $userTier = 'free'
    ): array {
        if ($gameCount <= 0) {
            $gameCount = $this->defaultGameCount();
        }

        $games = $this->fetchRecentGames($team, $gameCount, $season, $seasonType, $beforeDate);

        if ($games->isEmpty()) {
            return ['trends' => [], 'locked' => []];
        }

        $trends = [];
        $enabledCollectors = config('trends.collectors', []);

        foreach ($this->collectors() as $collectorClass) {
            $collector = app($collectorClass);
            $key = $collector->key();

            if (! ($enabledCollectors[$key] ?? true)) {
                continue;
            }

            $collector->setContext($this->sportKey(), $team, $games);
            if (! $collector->hasGames()) {
                continue;
            }
            $messages = $collector->collect();

            if (! empty($messages)) {
                $trends[$key] = $messages;
            }
        }

        $trends = $this->normalizeTrendOutput($trends);

        return ['trends' => $trends, 'locked' => []];
    }

    /**
     * @param  array<string, array<int, string>>  $trends
     * @return array<string, array<int, string>>
     */
    protected function normalizeTrendOutput(array $trends): array
    {
        if (
            config('trends.defaults.dedupe_first_score_when_quarters_present', true)
            && array_key_exists('quarters', $trends)
            && array_key_exists('first_score', $trends)
        ) {
            unset($trends['first_score']);
        }

        $maxPerCategory = (int) config('trends.defaults.max_messages_per_category', 4);
        if ($maxPerCategory > 0) {
            foreach ($trends as $category => $messages) {
                $trends[$category] = array_slice($messages, 0, $maxPerCategory);
            }
        }

        return $trends;
    }

    protected function fetchRecentGames(
        object $team,
        int $count,
        ?int $season = null,
        ?string $seasonType = null,
        ?string $beforeDate = null
    ): Collection {
        $query = $this->baseGamesQuery($team, $season, $seasonType, $beforeDate)
            ->with($this->gameRelations())
            ->orderByDesc('game_date')
            ->limit($count);

        return $query->get();
    }

    public function countAvailableGames(object $team, ?int $season = null, ?string $seasonType = null, ?string $beforeDate = null): int
    {
        return (int) $this->baseGamesQuery($team, $season, $seasonType, $beforeDate)->count();
    }

    protected function baseGamesQuery(
        object $team,
        ?int $season = null,
        ?string $seasonType = null,
        ?string $beforeDate = null
    ): Builder {
        $model = $this->gameModel();
        $query = $model::query()
            ->where('status', 'STATUS_FINAL')
            ->where(fn ($q) => $q
                ->where('home_team_id', $team->id)
                ->orWhere('away_team_id', $team->id))
            ->when($season, fn ($q) => $q->where('season', $season))
            ->when(
                $seasonType,
                fn ($q) => $q->whereIn('season_type', $this->resolveSeasonTypeCandidates($seasonType))
            )
            ->when($beforeDate, fn ($q) => $this->applyBeforeDateFilter($q, $beforeDate));

        if ($seasonType === null && $this->usesAnalyticsSeasonTypes()) {
            $query->when(
                config($this->analyticsSeasonTypesConfigKey()),
                fn ($q, $types) => $q->whereIn('season_type', $types)
            );
        }

        return $query;
    }

    protected function applyBeforeDateFilter(Builder $query, string $beforeDate): Builder
    {
        $cutoff = Carbon::parse($beforeDate);
        $date = $cutoff->toDateString();
        $hasTime = preg_match('/[T ]\d{1,2}:\d{2}/', $beforeDate) === 1;

        if (! $hasTime) {
            return $query->whereDate('game_date', '<', $date);
        }

        $time = $cutoff->format('H:i:s');

        return $query->where(function (Builder $inner) use ($date, $time): void {
            $inner->whereDate('game_date', '<', $date)
                ->orWhere(function (Builder $sameDate) use ($date, $time): void {
                    $sameDate->whereDate('game_date', $date)
                        ->whereTime('game_time', '<', $time);
                });
        });
    }

    /**
     * @return array<int, class-string>
     */
    protected function collectors(): array
    {
        return static::COLLECTORS;
    }

    /**
     * @return array<int, string>
     */
    protected function gameRelations(): array
    {
        return static::GAME_RELATIONS;
    }

    protected function usesAnalyticsSeasonTypes(): bool
    {
        return static::USES_ANALYTICS_SEASON_TYPES;
    }

    protected function analyticsSeasonTypesConfigKey(): string
    {
        if (static::ANALYTICS_SEASON_TYPES_CONFIG_KEY === '') {
            throw new \RuntimeException('ANALYTICS_SEASON_TYPES_CONFIG_KEY must be defined when USES_ANALYTICS_SEASON_TYPES is true.');
        }

        return static::ANALYTICS_SEASON_TYPES_CONFIG_KEY;
    }

    protected function sportKey(): string
    {
        if (static::SPORT_KEY === '') {
            throw new \RuntimeException('SPORT_KEY must be defined on team trends action.');
        }

        return static::SPORT_KEY;
    }

    protected function gameModel(): string
    {
        if (static::GAME_MODEL === '') {
            throw new \RuntimeException('GAME_MODEL must be defined on team trends action.');
        }

        return static::GAME_MODEL;
    }

    protected function defaultGameCount(): int
    {
        return static::DEFAULT_GAME_COUNT;
    }

    protected function requiredTier(string $category): string
    {
        return (string) config("trends.tier_requirements.{$category}", 'free');
    }

    protected function tierAllows(string $userTier, string $requiredTier): bool
    {
        $levels = config('trends.tier_levels', []);
        $userLevel = (int) ($levels[$userTier] ?? $levels['free'] ?? 0);
        $requiredLevel = (int) ($levels[$requiredTier] ?? $levels['free'] ?? 0);

        return $userLevel >= $requiredLevel;
    }

    /**
     * @return array<int, int|string>
     */
    protected function resolveSeasonTypeCandidates(int|string $seasonType): array
    {
        $sportSlug = $this->sportKey();
        $typeNames = config("{$sportSlug}.season.type_names", []);
        $typesByKey = config("{$sportSlug}.season.types", []);
        $candidates = [$seasonType, (string) $seasonType];

        if (is_string($seasonType) && isset($typeNames[$seasonType])) {
            $candidates[] = $typeNames[$seasonType];
        }

        if (is_string($seasonType) && isset($typesByKey[$seasonType])) {
            $resolved = $typesByKey[$seasonType];
            $candidates[] = $resolved;
            $candidates[] = (string) $resolved;
        }

        if (is_numeric($seasonType)) {
            $code = (int) $seasonType;
            $matchedKey = array_search($code, $typesByKey, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = $typeNames[$matchedKey];
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($value) => $value !== null && $value !== ''
        )));
    }
}
