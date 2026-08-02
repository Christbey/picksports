<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\StartingPitcherForecast;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MlbStartingPitcherForecastService
{
    /**
     * @param  array{pitcher_espn_id?: ?string, confidence?: ?float, evidence?: array<string, mixed>}  $projection
     */
    public function record(Game $game, string $side, array $projection): ?StartingPitcherForecast
    {
        $pitcherEspnId = trim((string) ($projection['pitcher_espn_id'] ?? ''));
        if ($pitcherEspnId === '' || ! in_array($side, ['home', 'away'], true)) {
            return null;
        }

        $forecastedAt = $game->pitcher_projection_generated_at
            ? CarbonImmutable::instance($game->pitcher_projection_generated_at)
            : now()->toImmutable();
        $gameStart = MlbGameStart::for($game);
        $evidence = is_array($projection['evidence'] ?? null) ? $projection['evidence'] : [];
        $modelVersion = (string) data_get(
            $game->pitcher_projection_metadata,
            'version',
            config('mlb.starter_projection.version', 'rotation-v1')
        );
        $rating = $this->ratingSnapshot($game, $pitcherEspnId);
        $confidence = isset($projection['confidence']) && is_numeric($projection['confidence'])
            ? max(0.001, min(0.999, (float) $projection['confidence']))
            : null;

        $hashPayload = [
            'game_id' => $game->id,
            'side' => $side,
            'model_version' => $modelVersion,
            'predicted_pitcher_espn_id' => $pitcherEspnId,
            'confidence' => $confidence,
            'evidence' => $evidence,
        ];

        $forecast = StartingPitcherForecast::query()->firstOrCreate(
            ['forecast_hash' => $this->stableHash($hashPayload)],
            [
                'game_id' => $game->id,
                'team_id' => $side === 'home' ? $game->home_team_id : $game->away_team_id,
                'season' => $game->season,
                'side' => $side,
                'model_version' => $modelVersion,
                'prediction_source' => 'rotation_projection',
                'predicted_pitcher_espn_id' => $pitcherEspnId,
                'confidence' => $confidence,
                'predicted_pitcher_rating' => $rating['rating'],
                'predicted_rating_source' => $rating['source'],
                'evidence' => $evidence,
                'forecasted_at' => $forecastedAt,
                'game_start_at' => $gameStart,
                'known_before_game_start' => $gameStart !== null && $forecastedAt->lt($gameStart),
            ]
        );

        $actualPitcherEspnId = $side === 'home'
            ? $game->actual_home_pitcher_espn_id
            : $game->actual_away_pitcher_espn_id;

        if (filled($actualPitcherEspnId)) {
            $this->grade($forecast, $game, (string) $actualPitcherEspnId);
        }

        return $forecast->fresh(['predictedPitcher', 'actualPitcher']);
    }

    public function recordExistingProjections(Game $game): int
    {
        $recorded = 0;

        foreach (['home', 'away'] as $side) {
            $pitcherEspnId = $side === 'home'
                ? $game->projected_home_pitcher_espn_id
                : $game->projected_away_pitcher_espn_id;
            $confidence = $side === 'home'
                ? $game->projected_home_pitcher_confidence
                : $game->projected_away_pitcher_confidence;

            if ($this->record($game, $side, [
                'pitcher_espn_id' => $pitcherEspnId,
                'confidence' => $confidence,
                'evidence' => data_get($game->pitcher_projection_metadata, $side, []),
            ])) {
                $recorded++;
            }
        }

        return $recorded;
    }

    public function confirmGame(Game $game): int
    {
        $this->recordExistingProjections($game);
        $graded = 0;

        foreach (['home', 'away'] as $side) {
            $actualPitcherEspnId = $side === 'home'
                ? $game->actual_home_pitcher_espn_id
                : $game->actual_away_pitcher_espn_id;

            if (! filled($actualPitcherEspnId)) {
                continue;
            }

            StartingPitcherForecast::query()
                ->where('game_id', $game->id)
                ->where('side', $side)
                ->each(function (StartingPitcherForecast $forecast) use ($game, $actualPitcherEspnId, &$graded): void {
                    $this->grade($forecast, $game, (string) $actualPitcherEspnId);
                    $graded++;
                });
        }

        return $graded;
    }

    public function reconcileSeason(int $season): array
    {
        $games = 0;
        $graded = 0;

        Game::query()
            ->where('season', $season)
            ->where(function ($query): void {
                $query->whereNotNull('actual_home_pitcher_espn_id')
                    ->orWhereNotNull('actual_away_pitcher_espn_id');
            })
            ->chunkById(100, function (Collection $chunk) use (&$games, &$graded): void {
                foreach ($chunk as $game) {
                    $games++;
                    $graded += $this->confirmGame($game);
                }
            });

        return ['games' => $games, 'graded_forecasts' => $graded];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(int $season, bool $includePostStart = false): array
    {
        $forecasts = StartingPitcherForecast::query()
            ->with('predictedPitcher')
            ->where('season', $season)
            ->whereNotNull('graded_at')
            ->when(! $includePostStart, fn ($query) => $query->pregameSafe())
            ->get();

        return [
            'season' => $season,
            'pregame_safe_only' => ! $includePostStart,
            'summary' => $this->metrics($forecasts),
            'by_confidence' => $forecasts
                ->groupBy(fn (StartingPitcherForecast $forecast): string => $this->confidenceBucket($forecast->confidence))
                ->map(fn (Collection $rows, string $bucket): array => ['bucket' => $bucket, ...$this->metrics($rows)])
                ->values()
                ->all(),
            'by_model' => $forecasts
                ->groupBy('model_version')
                ->map(fn (Collection $rows, string $version): array => ['model_version' => $version, ...$this->metrics($rows)])
                ->values()
                ->all(),
            'by_pitcher' => $forecasts
                ->groupBy('predicted_pitcher_espn_id')
                ->map(function (Collection $rows, string $pitcherEspnId): array {
                    $first = $rows->first();

                    return [
                        'pitcher_espn_id' => $pitcherEspnId,
                        'pitcher_name' => $first?->predictedPitcher?->full_name,
                        ...$this->metrics($rows),
                    ];
                })
                ->sortByDesc('forecasts')
                ->values()
                ->all(),
        ];
    }

    private function grade(StartingPitcherForecast $forecast, Game $game, string $actualPitcherEspnId): void
    {
        $correct = hash_equals($forecast->predicted_pitcher_espn_id, $actualPitcherEspnId);
        $outcome = $correct ? 1.0 : 0.0;
        $confidence = $forecast->confidence !== null
            ? max(0.001, min(0.999, (float) $forecast->confidence))
            : null;
        $actualRating = $this->ratingSnapshot($game, $actualPitcherEspnId);

        $forecast->forceFill([
            'actual_pitcher_espn_id' => $actualPitcherEspnId,
            'actual_pitcher_rating' => $actualRating['rating'],
            'actual_rating_source' => $actualRating['source'],
            'confirmation_source' => 'espn_boxscore',
            'confirmed_at' => $game->starting_pitchers_confirmed_at ?? now(),
            'is_correct' => $correct,
            'starter_changed' => ! $correct,
            'confidence_error' => $confidence !== null ? abs($confidence - $outcome) : null,
            'brier_score' => $confidence !== null ? ($confidence - $outcome) ** 2 : null,
            'log_loss' => $confidence !== null
                ? -($outcome * log($confidence) + (1 - $outcome) * log(1 - $confidence))
                : null,
            'rating_difference' => $actualRating['rating'] !== null && $forecast->predicted_pitcher_rating !== null
                ? $actualRating['rating'] - $forecast->predicted_pitcher_rating
                : null,
            'grade' => $correct ? 'correct' : 'incorrect',
            'graded_at' => now(),
        ])->save();
    }

    /**
     * @return array{rating: ?float, source: ?string}
     */
    private function ratingSnapshot(Game $game, string $pitcherEspnId): array
    {
        $player = Player::query()->where('espn_id', $pitcherEspnId)->first();
        if (! $player) {
            return ['rating' => null, 'source' => null];
        }

        $historicalRating = PitcherEloRating::query()
            ->where('player_id', $player->id)
            ->whereDate('date', '<', $game->game_date)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->value('elo_rating');

        if ($historicalRating !== null) {
            return ['rating' => (float) $historicalRating, 'source' => 'pregame_pitcher_elo_history'];
        }

        return is_numeric($player->elo_rating)
            ? ['rating' => (float) $player->elo_rating, 'source' => 'player_rating_at_forecast']
            : ['rating' => null, 'source' => null];
    }

    /**
     * @param  Collection<int, StartingPitcherForecast>  $forecasts
     * @return array<string, int|float|null>
     */
    private function metrics(Collection $forecasts): array
    {
        if ($forecasts->isEmpty()) {
            return [
                'forecasts' => 0,
                'correct' => 0,
                'accuracy' => null,
                'average_confidence' => null,
                'average_brier' => null,
                'average_log_loss' => null,
                'rating_mae' => null,
            ];
        }

        $ratingDifferences = $forecasts->pluck('rating_difference')->filter(fn ($value) => $value !== null);

        return [
            'forecasts' => $forecasts->count(),
            'correct' => $forecasts->where('is_correct', true)->count(),
            'accuracy' => round($forecasts->where('is_correct', true)->count() / $forecasts->count(), 6),
            'average_confidence' => $this->average($forecasts, 'confidence'),
            'average_brier' => $this->average($forecasts, 'brier_score'),
            'average_log_loss' => $this->average($forecasts, 'log_loss'),
            'rating_mae' => $ratingDifferences->isNotEmpty()
                ? round((float) $ratingDifferences->map(fn ($value): float => abs((float) $value))->avg(), 4)
                : null,
        ];
    }

    private function average(Collection $forecasts, string $field): ?float
    {
        $values = $forecasts->pluck($field)->filter(fn ($value) => $value !== null);

        return $values->isNotEmpty() ? round((float) $values->avg(), 6) : null;
    }

    private function confidenceBucket(?float $confidence): string
    {
        if ($confidence === null) {
            return 'unknown';
        }

        return match (true) {
            $confidence >= 0.75 => 'high',
            $confidence >= 0.55 => 'medium',
            default => 'low',
        };
    }

    private function stableHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
