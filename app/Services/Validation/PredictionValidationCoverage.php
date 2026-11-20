<?php

namespace App\Services\Validation;

use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;
use App\Support\SportCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PredictionValidationCoverage
{
    public function usesCanonical(string $sport): bool
    {
        return (bool) config("prediction_lifecycle.canonical_pipeline.{$sport}", false)
            || (bool) config("prediction_lifecycle.canonical_reads.{$sport}", false);
    }

    /** Both readiness checks must inspect the same configured prediction lifecycle. */
    public function forGames(string $sport, Collection $games): Collection
    {
        if (! in_array($sport, SportCatalog::ALL, true)) {
            throw new InvalidArgumentException('Unsupported validation sport.');
        }
        if (! $this->usesCanonical($sport)) {
            return DB::table($sport.'_predictions')->whereIn('game_id', $games->pluck('id'))->get(['game_id'])->keyBy('game_id');
        }
        if ($sport === 'cfb') {
            return app(CfbValidationCoverage::class)->publishedForGames($games);
        }

        $forecasts = app(CanonicalSportPredictionQuery::class)->queryForSport($sport)
            ->whereIn("{$sport}_games.id", $games->pluck('id'))
            ->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->select(['predictions.id', 'predictions.sport', 'predictions.sport_event_id', 'predictions.calculation_run_id', 'predictions.generated_at', 'predictions.published_at', 'predictions.revision'])
            ->withOnly([])->get()->unique('sport_event_id')->keyBy('sport_event_id');

        if (in_array($sport, ['cbb', 'wcbb'], true)) {
            $forecasts->load([
                'calculationRun:id,calculation_release_id,event_input_snapshot_id',
                'calculationRun.release:id,sport,phase,status,configuration',
                'calculationRun.inputSnapshot' => fn ($query) => $query->select([
                    'id', 'sport_event_id', 'sport', 'phase', 'captured_at', 'cutoff_at', 'pregame_safety_status',
                    'inputs->home as validation_home', 'inputs->away as validation_away', 'inputs->event as validation_event',
                ]),
            ]);
        }

        return $forecasts;
    }

    /** Explicit placeholder opponents are expected forecast holds; missing team records are not. */
    public function expectedHolds(string $sport, Collection $games): Collection
    {
        if (! in_array($sport, ['cbb', 'wcbb'], true) || ! $this->usesCanonical($sport)) {
            return collect();
        }
        $gameClass = config("validation.sports.{$sport}.models.game");
        $eligibility = app(CollegeBasketballPregameEligibility::class);

        return $gameClass::query()->whereKey($games->pluck('id'))->select(['id', 'home_team_id', 'away_team_id'])
            ->with(['homeTeam:id,school,abbreviation,espn_id', 'awayTeam:id,school,abbreviation,espn_id'])->get()
            ->filter(fn (Model $game): bool => $eligibility->hasPlaceholderTeams($game->homeTeam, $game->awayTeam))
            ->mapWithKeys(fn (Model $game): array => [$game->id => 'placeholder_teams']);
    }

    /** @return array<string, string> Valid selected frozen metrics, never guessed from another forecast or release. */
    public function frozenMetricSources(object $game, mixed $forecast): array
    {
        if (! $forecast instanceof CanonicalPrediction || ! in_array($forecast->sport, ['cbb', 'wcbb'], true)) {
            return [];
        }
        $run = $forecast->getRelationValue('calculationRun');
        $snapshot = $run?->getRelationValue('inputSnapshot');
        $release = $run?->getRelationValue('release');
        if ($snapshot === null || $release === null || $snapshot->sport_event_id !== $game->sport_event_id
            || $snapshot->sport !== $forecast->sport || $release->sport !== $forecast->sport
            || $snapshot->phase !== 'pregame' || $release->phase !== 'pregame'
            || ! in_array($release->status, ['approved', 'retired'], true)
            || $snapshot->captured_at === null || $snapshot->captured_at->gt(now())
            || $snapshot->cutoff_at === null || $snapshot->captured_at->gt($snapshot->cutoff_at)) {
            return [];
        }
        $event = $this->decoded($snapshot->getAttribute('validation_event'));
        if ((int) ($event['season'] ?? 0) !== (int) $game->season) {
            return [];
        }
        $sources = [];
        foreach (['home', 'away'] as $side) {
            $inputs = $this->decoded($snapshot->getAttribute('validation_'.$side));
            $metrics = $inputs['metrics'] ?? null;
            if ((int) ($inputs['team_id'] ?? 0) !== (int) $game->{$side.'_team_id'} || ! is_array($metrics)) {
                continue;
            }
            foreach (['offensive_efficiency', 'defensive_efficiency', 'tempo'] as $field) {
                if (! is_numeric($metrics[$field] ?? null) || ! is_finite((float) $metrics[$field]) || (float) $metrics[$field] <= 0) {
                    continue 2;
                }
            }
            $season = $metrics['record_season'] ?? null;
            if (! is_numeric($season) || (int) $season < 1 || (float) $season !== (float) (int) $season) {
                continue;
            }
            if ((int) $season === (int) $game->season) {
                $sources[$side] = 'frozen_current_season';
            } elseif ((int) $season < (int) $game->season
                && data_get($release->configuration, 'inputs.use_previous_season_metrics_fallback') === true
                && (! data_get($release->configuration, 'inputs.recover_invalid_team_metrics') || (int) $season === (int) $game->season - 1)) {
                $sources[$side] = 'frozen_prior_season';
            }
        }

        return $sources;
    }

    /** @return array<string, mixed> */
    private function decoded(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    public function key(string $sport, object $game): int|string|null
    {
        return $this->usesCanonical($sport) ? $game->sport_event_id : $game->id;
    }
}
