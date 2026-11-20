<?php

namespace App\Services\WCBB;

use App\Concerns\FiltersTeamGames;
use App\Models\WCBB\Game;
use App\Models\WCBB\TeamMetric;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class WcbbMetricSourceEvidence
{
    use FiltersTeamGames;

    private array $primedGames = [];

    private ?Collection $primedMetrics = null;

    private ?CarbonImmutable $observedAt = null;

    /** This instance belongs to one read-only evaluation and is never globally cached. */
    public function prime(Collection $games, CarbonInterface $observedAt): void
    {
        $this->observedAt = CarbonImmutable::instance($observedAt);
        $teamIds = $games->pluck('home_team_id')->merge($games->pluck('away_team_id'))->unique()->values();
        $seasons = $games->pluck('season')->unique();
        $metricSeasons = $seasons->flatMap(fn ($season): array => [(int) $season, (int) $season - 1])->unique()->all();
        $this->primedMetrics = TeamMetric::query()->whereIn('team_id', $teamIds)->whereIn('season', $metricSeasons)
            ->where('updated_at', '<=', $this->observedAt)->get();
        foreach ($seasons as $season) {
            $this->primedGames[(int) $season] = $this->sourceGames($teamIds->all(), (int) $season, $this->observedAt);
        }
    }

    /** @return array{current: ?TeamMetric, previous: ?TeamMetric} */
    public function metricCandidates(int $teamId, int $season, CarbonInterface $capturedAt, string $throughDate): array
    {
        $throughDate = min($throughDate, $capturedAt->toDateString());
        $metrics = $this->primedMetrics ?? TeamMetric::query()->where('team_id', $teamId)->whereIn('season', [$season, $season - 1])
            ->where('updated_at', '<=', $capturedAt)->whereDate('calculation_date', '<=', $throughDate)->get();
        $eligible = $metrics->filter(fn (TeamMetric $metric): bool => (int) $metric->team_id === $teamId && $metric->calculation_date !== null
            && $metric->updated_at->lessThanOrEqualTo($capturedAt)
            && $metric->calculation_date?->toDateString() <= $throughDate)
            ->sort(fn (TeamMetric $a, TeamMetric $b): int => strcmp($b->calculation_date?->toDateString() ?? '', $a->calculation_date?->toDateString() ?? '') ?: ($b->id <=> $a->id));

        return ['current' => $eligible->first(fn (TeamMetric $metric): bool => (int) $metric->season === $season),
            'previous' => $eligible->first(fn (TeamMetric $metric): bool => (int) $metric->season === $season - 1)];
    }

    public function statIsUsable(Model $stat, mixed $expectedPoints = null, ?CarbonInterface $observedAt = null): bool
    {
        if ($observedAt !== null && (($stat->created_at !== null && $stat->created_at->greaterThan($observedAt))
            || ($stat->updated_at !== null && $stat->updated_at->greaterThan($observedAt)))) {
            return false;
        }
        if (! $this->nonNegativeNumber($stat->getAttribute('points'))) {
            return false;
        }
        if ($expectedPoints !== null && (! is_numeric($expectedPoints) || (float) $stat->points !== (float) $expectedPoints)) {
            return false;
        }
        $possessions = $stat->getAttribute('possessions');
        if ($possessions !== null) {
            return is_numeric($possessions) && is_finite((float) $possessions) && (float) $possessions > 0;
        }
        foreach (['field_goals_attempted', 'offensive_rebounds', 'turnovers', 'free_throws_attempted'] as $field) {
            if (! $this->nonNegativeNumber($stat->getAttribute($field))) {
                return false;
            }
        }
        $estimate = (float) $stat->field_goals_attempted - (float) $stat->offensive_rebounds
            + (float) $stat->turnovers + (float) config('wcbb.metrics.possession_coefficient', 0.4) * (float) $stat->free_throws_attempted;

        return is_finite($estimate) && $estimate > 0;
    }

    /** @return array{completed_games: int, usable_paired_games: int} */
    public function coverage(int $teamId, int $season, ?CarbonInterface $observedAt = null, ?string $throughDate = null): array
    {
        $observedAt ??= now();
        if ($this->observedAt !== null && $this->observedAt->lessThan($observedAt)) {
            $observedAt = $this->observedAt;
        }
        $games = ($this->primedGames[$season] ?? $this->sourceGames([$teamId], $season, $observedAt))
            ->filter(function (Game $game) use ($teamId, $observedAt, $throughDate): bool {
                $start = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);

                return in_array($teamId, [$game->home_team_id, $game->away_team_id], true)
                    && $start !== null && $start->lessThanOrEqualTo($observedAt)
                    && ($throughDate === null || $game->game_date->toDateString() <= $throughDate);
            });
        $usable = $games->filter(function (Game $game) use ($teamId, $observedAt): bool {
            if (! $this->nonNegativeNumber($game->home_score) || ! $this->nonNegativeNumber($game->away_score)) {
                return false;
            }
            $opponentId = $game->home_team_id === $teamId ? $game->away_team_id : $game->home_team_id;
            $team = $game->teamStats->firstWhere('team_id', $teamId);
            $opponent = $game->teamStats->firstWhere('team_id', $opponentId);

            return $team !== null && $opponent !== null
                && $this->statIsUsable($team, $game->home_team_id === $teamId ? $game->home_score : $game->away_score, $observedAt)
                && $this->statIsUsable($opponent, $game->home_team_id === $teamId ? $game->away_score : $game->home_score, $observedAt);
        });

        return ['completed_games' => $games->count(), 'usable_paired_games' => $usable->count()];
    }

    private function sourceGames(array $teamIds, int $season, CarbonInterface $observedAt): Collection
    {
        $types = $this->resolveAnalyticsSeasonTypeCandidates('wcbb');

        return Game::query()->where('season', $season)->where('status', config('wcbb.statuses.final'))
            ->select(['id', 'home_team_id', 'away_team_id', 'season', 'season_type', 'status', 'game_date', 'game_time', 'home_score', 'away_score'])
            ->where(fn ($query) => $query->whereIn('home_team_id', $teamIds)->orWhereIn('away_team_id', $teamIds))
            ->when($types !== [], fn ($query) => $query->whereIn('season_type', $types))
            ->whereDate('game_date', '<=', CarbonImmutable::instance($observedAt)->utc()->addDay()->toDateString())
            ->with(['teamStats' => fn ($query) => $query->select(['id', 'team_id', 'game_id', 'points', 'possessions',
                'field_goals_attempted', 'offensive_rebounds', 'turnovers', 'free_throws_attempted', 'created_at', 'updated_at'])])->get();
    }

    private function nonNegativeNumber(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0;
    }
}
