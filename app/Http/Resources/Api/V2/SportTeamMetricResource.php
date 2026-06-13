<?php

namespace App\Http\Resources\Api\V2;

use App\Models\MLB\Game as MlbGame;
use App\Services\Api\V2\SportContext;
use App\Support\MlbRegularSeasonWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportTeamMetricResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly SportContext $context,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metrics = $this->metrics();
        $record = $this->record($metrics);

        return [
            ...$metrics,
            'id' => $this->attribute('id'),
            'sport' => $this->context->slug,
            'team_id' => $this->attribute('team_id'),
            'season' => $this->attribute('season'),
            'season_type' => $this->attribute('season_type'),
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'games_played' => $record['games_played'],
            'record' => $record,
            'record_label' => $record['label'],
            'team' => $this->team(),
            'calculation_date' => $this->serializeDateValue($this->attribute('calculation_date')),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(): array
    {
        if (! $this->resource instanceof Model) {
            return [];
        }

        $metrics = collect($this->resource->getAttributes())
            ->except([
                'id',
                'team_id',
                'season',
                'season_type',
                'calculation_date',
                'created_at',
                'updated_at',
            ])
            ->filter(fn (mixed $value): bool => $value !== null)
            ->all();

        $this->aliasMetric($metrics, 'offensive_rating', 'offensive_efficiency');
        $this->aliasMetric($metrics, 'defensive_rating', 'defensive_efficiency');
        $this->aliasMetric($metrics, 'pace', 'tempo');

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{wins: int|null, losses: int|null, games_played: int, label: string|null, source: string}
     */
    private function record(array $metrics): array
    {
        $wins = $this->nullableInt($metrics['wins'] ?? $this->attribute('wins'));
        $losses = $this->nullableInt($metrics['losses'] ?? $this->attribute('losses'));
        $source = 'metric';

        if ($this->shouldDeriveMlbRecord($wins, $losses)) {
            $derived = $this->deriveMlbRecord();

            if ($derived['games_played'] > 0) {
                $wins = $derived['wins'];
                $losses = $derived['losses'];
                $source = 'derived_games';
            }
        }

        $gamesPlayed = $wins !== null && $losses !== null
            ? $wins + $losses
            : ($this->nullableInt($metrics['games_played'] ?? $this->attribute('games_played')) ?? 0);

        return [
            'wins' => $wins,
            'losses' => $losses,
            'games_played' => $gamesPlayed,
            'label' => $wins !== null && $losses !== null ? "{$wins}-{$losses}" : null,
            'source' => $source,
        ];
    }

    private function shouldDeriveMlbRecord(?int $wins, ?int $losses): bool
    {
        return $this->context->slug === 'mlb'
            && $this->attribute('team_id') !== null
            && $this->attribute('season') !== null
            && ($wins === null || $losses === null || ($wins + $losses) === 0);
    }

    /**
     * @return array{wins: int, losses: int, games_played: int}
     */
    private function deriveMlbRecord(): array
    {
        $teamId = (int) $this->attribute('team_id');
        $season = (int) $this->attribute('season');
        $seasonType = $this->attribute('season_type') ?? config('mlb.season.default_team_metrics_type');
        $finalStatus = (string) config('mlb.statuses.final');

        $query = MlbGame::query()
            ->with('teamStats')
            ->where('season', $season)
            ->where('season_type', $seasonType)
            ->where('status', $finalStatus)
            ->where(function ($query) use ($teamId) {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            });

        if ((string) $seasonType === (string) config('mlb.season.types.regular')
            && ($openerDate = MlbRegularSeasonWindow::openerDate($season)) !== null) {
            $query->whereDate('game_date', '>=', $openerDate);
        }

        $wins = 0;
        $losses = 0;

        $query->get(['id', 'home_team_id', 'away_team_id', 'home_score', 'away_score'])
            ->each(function (MlbGame $game) use ($teamId, &$wins, &$losses): void {
                $isHome = (int) $game->home_team_id === $teamId;
                [$teamScore, $opponentScore] = $this->resolvedMlbScore($game, $teamId, $isHome);

                if ($teamScore > $opponentScore) {
                    $wins++;
                } elseif ($teamScore < $opponentScore) {
                    $losses++;
                }
            });

        return [
            'wins' => $wins,
            'losses' => $losses,
            'games_played' => $wins + $losses,
        ];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolvedMlbScore(MlbGame $game, int $teamId, bool $isHome): array
    {
        $gameTeamScore = $isHome ? $game->home_score : $game->away_score;
        $gameOpponentScore = $isHome ? $game->away_score : $game->home_score;
        $teamScore = (float) ($gameTeamScore ?? 0);
        $opponentScore = (float) ($gameOpponentScore ?? 0);

        if ($teamScore !== 0.0 || $opponentScore !== 0.0) {
            return [$teamScore, $opponentScore];
        }

        $teamStat = $game->teamStats->firstWhere('team_id', $teamId)
            ?? $game->teamStats->firstWhere('team_type', $isHome ? 'home' : 'away');
        $opponentStat = $game->teamStats->firstWhere('team_type', $isHome ? 'away' : 'home');

        if (! $opponentStat) {
            $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
            $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);
        }

        if ($teamStat?->runs !== null && $opponentStat?->runs !== null) {
            return [(float) $teamStat->runs, (float) $opponentStat->runs];
        }

        return [$teamScore, $opponentScore];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function aliasMetric(array &$metrics, string $alias, string $source): void
    {
        if (! array_key_exists($alias, $metrics) && array_key_exists($source, $metrics)) {
            $metrics[$alias] = $metrics[$source];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(): ?array
    {
        $team = $this->relation('team');

        if (! $team) {
            return null;
        }

        return [
            'id' => $team->getAttribute('id'),
            'abbreviation' => $team->getAttribute('abbreviation'),
            'display_name' => $team->getAttribute('display_name')
                ?? trim((string) ($team->getAttribute('location') ?? $team->getAttribute('school')).' '.(string) ($team->getAttribute('name') ?? $team->getAttribute('mascot'))),
        ];
    }

    private function relation(string $relation): ?Model
    {
        if (! $this->resource instanceof Model || ! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $related = $this->resource->getRelation($relation);

        return $related instanceof Model ? $related : null;
    }

    private function attribute(string $key): mixed
    {
        if (! $this->resource instanceof Model) {
            return null;
        }

        return array_key_exists($key, $this->resource->getAttributes())
            ? $this->resource->getAttribute($key)
            : null;
    }

    private function serializeDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
