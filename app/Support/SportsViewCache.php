<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SportsViewCache
{
    private const NAMESPACE_KEY = 'sports_view_cache:namespace';

    public const SEGMENT_DASHBOARD = 'dashboard';

    public const SEGMENT_LIVE_SCOREBOARD = 'live_scoreboard';

    public const SEGMENT_TEAM_GAMES_BY_TEAM = 'team_games_by_team';

    public const SEGMENT_TEAM_METRICS_INDEX = 'team_metrics_index';

    public const SEGMENT_TEAM_METRICS_BY_TEAM = 'team_metrics_by_team';

    public const SEGMENT_TEAM_METRICS_AVAILABLE_SEASONS = 'team_metrics_available_seasons';

    public const SEGMENT_TEAM_STATS_INDEX = 'team_stats_index';

    public const SEGMENT_TEAM_STATS_BY_GAME = 'team_stats_by_game';

    public const SEGMENT_TEAM_STATS_BY_TEAM = 'team_stats_by_team';

    public const SEGMENT_TEAM_STATS_SEASON_AVERAGES = 'team_stats_season_averages';

    public const SEGMENT_TEAM_STATS_ALL_SEASON_AVERAGES = 'team_stats_all_season_averages';

    public const SEGMENT_TEAM_TRENDS = 'team_trends';

    public const SEGMENT_PREDICTIONS_INDEX = 'predictions_index';

    public const SEGMENT_PREDICTIONS_BY_GAME = 'predictions_by_game';

    public const SEGMENT_PREDICTIONS_AVAILABLE_DATES = 'predictions_available_dates';

    public const SEGMENT_PREDICTIONS_AVAILABLE_SEASONS = 'predictions_available_seasons';

    public const SEGMENT_PLAYER_PROPS_PAGE = 'player_props_page';

    public const SEGMENT_PLAYER_LEADERBOARDS = 'player_leaderboards';

    public const SEGMENT_PLAYER_STAT_SEASONS = 'player_stat_seasons';

    public const SEGMENT_FUTURES_FORECASTS = 'futures_forecasts';

    /**
     * @template T
     *
     * @param  Closure():T  $resolver
     * @return T
     */
    public function remember(string $segment, string $key, int $ttlSeconds, Closure $resolver)
    {
        return Cache::remember(
            $this->cacheKey($segment, $key),
            now()->addSeconds(max(1, $ttlSeconds)),
            $resolver
        );
    }

    public function bustAll(): void
    {
        Cache::forever(self::NAMESPACE_KEY, Str::uuid()->toString());
    }

    public function bustSegment(string $segment): void
    {
        Cache::forever($this->segmentNamespaceKey($segment), Str::uuid()->toString());
    }

    /**
     * @param  array<int, string>  $segments
     */
    public function bustSegments(array $segments): void
    {
        foreach (array_values(array_unique($segments)) as $segment) {
            $this->bustSegment($segment);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function contextHash(array $context): string
    {
        return md5(json_encode($this->normalize($context), JSON_UNESCAPED_SLASHES));
    }

    private function cacheKey(string $segment, string $key): string
    {
        return "sports_view_cache:{$this->namespaceToken()}:{$this->segmentNamespaceToken($segment)}:{$segment}:{$key}";
    }

    private function namespaceToken(): string
    {
        return Cache::rememberForever(
            self::NAMESPACE_KEY,
            fn (): string => Str::uuid()->toString()
        );
    }

    private function segmentNamespaceToken(string $segment): string
    {
        return Cache::rememberForever(
            $this->segmentNamespaceKey($segment),
            fn (): string => Str::uuid()->toString()
        );
    }

    private function segmentNamespaceKey(string $segment): string
    {
        return 'sports_view_cache:segment_namespace:'.$segment;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $value;
    }
}
