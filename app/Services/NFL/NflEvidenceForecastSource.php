<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class NflEvidenceForecastSource
{
    public const VERSION = 'nfl-evidence-source-v1';

    public function usesCanonical(): bool
    {
        return (bool) config('prediction_lifecycle.canonical_pipeline.nfl', false)
            || (bool) config('prediction_lifecycle.canonical_reads.nfl', false);
    }

    public function name(?string $requested = null): string
    {
        $name = $requested ?? ($this->usesCanonical() ? 'canonical' : 'legacy');
        if (! in_array($name, ['canonical', 'legacy'], true)) {
            throw new \InvalidArgumentException('Forecast source must be canonical or legacy.');
        }

        return $name;
    }

    /** @param Collection<int, Game> $games @return Collection<int, object> */
    public function forGames(Collection $games, string $version, bool $forCapture = false): Collection
    {
        $forecasts = collect();
        if ($games->isEmpty()) {
            return $forecasts;
        }
        app(CanonicalSportPredictionQuery::class)->queryForSport('nfl')
            ->whereIn('nfl_games.id', $games->keys())
            ->where('predictions.generated_at', '<=', now())
            ->when($forCapture, fn ($query) => $query->where('predictions.generated_at', '>=', now()->subHours(2)))
            ->select(['predictions.id', 'predictions.public_id', 'predictions.sport', 'predictions.sport_event_id',
                'predictions.calculation_run_id', 'predictions.phase', 'predictions.publication_state',
                'predictions.generated_at', 'predictions.published_at', 'predictions.model_version'])
            ->withOnly([
                'markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
                'calculationRun.release:id,status', 'sportEvent:id,starts_at', 'sportEvent.nflGame:id,sport_event_id',
                'calculationRun.inputSnapshot' => fn (BelongsTo $query): BelongsTo => $query->select([
                    'id', 'sport', 'sport_event_id', 'phase', 'pregame_safety_status', 'captured_at', 'cutoff_at',
                    'latest_source_available_at', 'content_hash', 'schema_version',
                    'inputs->event->starts_at as audit_game_start_at',
                    'inputs->recommendation_context->version as audit_context_version',
                    'inputs->recommendation_context->model_version as audit_model_version',
                    'inputs->recommendation_context->outputs as audit_outputs',
                    'inputs->recommendation_context->evidence_source_version as capture_version',
                    'inputs->recommendation_context->runtime_configuration_hash as capture_config_hash',
                    ...($forCapture ? ['inputs->recommendation_context->model_metadata as capture_metadata',
                        'inputs->event->game_id as capture_game_id'] : []),
                    'inputs->pregame_market->available as audit_market_available',
                    'inputs->pregame_market->consensus as audit_market_consensus',
                ])->withCasts(['audit_outputs' => 'array', 'audit_market_consensus' => 'array', 'capture_metadata' => 'array']),
            ])->chunk(25, function (Collection $predictions) use ($forecasts, $version, $forCapture): void {
                foreach ($predictions as $prediction) {
                    $input = $prediction->calculationRun?->inputSnapshot;
                    if ($input?->getAttribute('audit_context_version') !== NflRecommendationInputBuilder::VERSION
                        || $input?->getAttribute('audit_model_version') !== $version) {
                        continue;
                    }
                    if ($forCapture && ($input->getAttribute('capture_version') !== self::VERSION
                        || ! is_string($input->getAttribute('capture_config_hash'))
                        || ! preg_match('/^[a-f0-9]{64}$/', $input->getAttribute('capture_config_hash')))) {
                        continue;
                    }
                    $start = $input->getAttribute('audit_game_start_at');
                    if (! is_string($start) || $start === '') {
                        continue;
                    }
                    try {
                        $frozenStart = CarbonImmutable::parse($start);
                    } catch (\Throwable) {
                        continue;
                    }
                    $snapshot = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
                    if ($snapshot === null || $prediction->published_at->gte($frozenStart)
                        || $prediction->generated_at->gte($frozenStart) || $input->captured_at->gt($frozenStart)
                        || $input->cutoff_at->gt($frozenStart)
                        || ($forCapture && (int) $input->getAttribute('capture_game_id') !== $snapshot->game_id)
                        || $forecasts->has($snapshot->game_id)) {
                        continue;
                    }
                    $market = in_array($input->getAttribute('audit_market_available'), [true, 1, '1', 'true'], true)
                        ? (array) $input->getAttribute('audit_market_consensus') : [];
                    $quoteTimes = [];
                    foreach (['winner' => 'moneyline', 'spread' => 'spread', 'total' => 'total'] as $type => $key) {
                        $quoteTimes[$type] = data_get($market, $key.'.freshness_observed_at')
                            ?? data_get($market, $key.'.provider_observed_at')
                            ?? data_get($market, $key.'.captured_at');
                    }
                    $line = data_get($market, 'spread.line');
                    $forecasts->put($snapshot->game_id, (object) [
                        'id' => null, 'prediction_table' => 'predictions', 'prediction_id' => $prediction->id,
                        'model_version' => $version, 'feature_hash' => $input->content_hash, 'config_hash' => $input->getAttribute('capture_config_hash'),
                        'capture_eligible' => $input->getAttribute('capture_version') === self::VERSION
                            && is_string($input->getAttribute('capture_config_hash'))
                            && preg_match('/^[a-f0-9]{64}$/', $input->getAttribute('capture_config_hash')) === 1,
                        'model_metadata' => (array) $input->getAttribute('capture_metadata'), 'lineage' => $snapshot->lineage,
                        'canonical_prediction_id' => $prediction->id,
                        'canonical_prediction_public_id' => $prediction->public_id,
                        'calculation_run_id' => $prediction->calculation_run_id, 'event_input_snapshot_id' => $input->id,
                        'game_id' => $snapshot->game_id, 'generated_at' => $prediction->generated_at,
                        'game_start_at' => $frozenStart, 'outputs' => (array) $input->getAttribute('audit_outputs'),
                        'market_context' => ['market_home_margin' => is_numeric($line) ? -(float) $line : null,
                            'market_total' => data_get($market, 'total.line'), 'quote_times' => $quoteTimes,
                            'odds_updated_at' => collect($quoteTimes)->filter()->sort()->last()],
                    ]);
                }
            });

        return $forecasts;
    }
}
