<?php

namespace App\Services\Predictions;

use App\Models\NFL\Game as NflGame;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\NFL\NflEvidenceForecastSource;
use App\Support\NflModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class EpaBlendCalibrationSource
{
    public const SPORTS = ['nfl', 'nba', 'cbb', 'wcbb'];

    public function name(string $sport, ?string $requested = null): string
    {
        if (! in_array($sport, self::SPORTS, true)) {
            throw new \InvalidArgumentException("Unsupported sport '{$sport}'. Allowed: ".implode(', ', self::SPORTS));
        }
        $source = $requested ?? ((config("prediction_lifecycle.canonical_pipeline.{$sport}") || config("prediction_lifecycle.canonical_reads.{$sport}")) ? 'canonical' : 'legacy');
        if (! in_array($source, ['canonical', 'legacy'], true)) {
            throw new \InvalidArgumentException('Forecast source must be canonical or legacy.');
        }

        return $source;
    }

    /** @return \Generator<int, object> */
    public function rows(string $sport, string $source, ?int $season, ?string $modelVersion = null): \Generator
    {
        $this->name($sport, $source);
        if ($source === 'legacy') {
            $query = DB::table("{$sport}_predictions as p")->join("{$sport}_games as g", 'g.id', '=', 'p.game_id')
                ->whereNotNull('p.graded_at')->whereNotNull('p.predicted_spread')->whereNotNull('p.actual_spread')
                ->when($season !== null, fn ($query) => $query->where('g.season', $season))
                ->select(['p.id', 'p.predicted_spread', 'p.actual_spread', 'p.predicted_total', 'p.actual_total', 'p.winner_correct', 'p.model_metadata']);
            foreach ($query->lazyById(250, 'p.id', 'id') as $row) {
                $row->epa_applied = $this->application($row->model_metadata, false);
                yield $row;
            }

            return;
        }
        if ($sport === 'nfl') {
            yield from $this->nflRows($season, $modelVersion ?? NflModelVersion::current());

            return;
        }
        yield from $this->basketballRows($sport, $season);
    }

    /** @return \Generator<int, object> */
    private function nflRows(?int $season, string $version): \Generator
    {
        $query = NflGame::query()->select(['id', 'sport_event_id', 'home_score', 'away_score'])->with('sportEvent:id,starts_at')
            ->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->whereHas('sportEvent', fn ($query) => $query->where('starts_at', '<', now()))
            ->when($season !== null, fn ($query) => $query->where('season', $season));
        foreach ($query->lazyById(250)->chunk(25) as $batch) {
            $games = $batch->collect()->keyBy('id');
            foreach (app(NflEvidenceForecastSource::class)->forGames($games, $version, includeMetadata: true) as $forecast) {
                $game = $games->get($forecast->game_id);
                if (! $forecast->game_start_at->eq($game->sportEvent?->starts_at)) {
                    continue;
                }
                $row = $this->outcome($game, $forecast->outputs['predicted_spread'] ?? null, $forecast->outputs['predicted_total'] ?? null, $forecast->model_metadata);
                if ($row !== null) {
                    $row->canonical_prediction_id = $forecast->canonical_prediction_id;
                    $row->canonical_lineage = $forecast->lineage;
                    yield $row;
                }
            }
        }
    }

    /** @return \Generator<int, object> */
    private function basketballRows(string $sport, ?int $season): \Generator
    {
        $seen = [];
        $query = app(CanonicalSportPredictionQuery::class)->queryForSport($sport, $season === null ? [] : ['season' => $season])
            ->select(['predictions.id', 'predictions.sport', 'predictions.sport_event_id', 'predictions.calculation_run_id',
                'predictions.phase', 'predictions.publication_state', 'predictions.generated_at', 'predictions.published_at',
                'predictions.model_version', 'predictions.output_metadata->true_epa as report_true_epa'])
            ->withCasts(['report_true_epa' => 'array'])
            ->where("{$sport}_games.status", 'STATUS_FINAL')->whereNotNull("{$sport}_games.home_score")->whereNotNull("{$sport}_games.away_score")
            ->where('sport_events.starts_at', '<', now())->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->withOnly(['markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status', 'calculationRun.release:id,status',
                'sportEvent:id,starts_at', "sportEvent.{$sport}Game:id,sport_event_id,home_score,away_score",
                'calculationRun.inputSnapshot' => fn (BelongsTo $query) => $query->select(['id', 'sport_event_id', 'sport', 'phase', 'schema_version',
                    'pregame_safety_status', 'captured_at', 'cutoff_at', 'latest_source_available_at', 'content_hash', 'inputs->event->starts_at as audit_starts_at']),
            ]);
        foreach ($query->lazy(25) as $prediction) {
            $snapshot = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
            $input = $prediction->calculationRun?->inputSnapshot;
            try {
                $start = $input?->getAttribute('audit_starts_at');
                $matches = is_string($start) && CarbonImmutable::parse($start)->eq($prediction->sportEvent?->starts_at);
            } catch (\Throwable) {
                $matches = false;
            }
            if (! $snapshot || ! $matches || isset($seen[$snapshot->game_id]) || $prediction->published_at->gte($snapshot->game_start_at)
                || ($input->latest_source_available_at && $input->latest_source_available_at->gt($prediction->generated_at))) {
                continue;
            }
            $row = $this->outcome($prediction->sportEvent->getRelation($sport.'Game'), $snapshot->outputs['predicted_spread'] ?? null,
                $snapshot->outputs['predicted_total'] ?? null, ['true_epa' => $prediction->getAttribute('report_true_epa')]);
            if ($row !== null) {
                $seen[$snapshot->game_id] = true;
                $row->canonical_prediction_id = $prediction->id;
                $row->canonical_lineage = $snapshot->lineage;
                yield $row;
            }
        }
    }

    private function outcome(object $game, mixed $spread, mixed $total, array $metadata): ?object
    {
        if (! is_numeric($spread) || ! is_finite((float) $spread)) {
            return null;
        }
        $margin = (float) $game->home_score - (float) $game->away_score;

        return (object) ['predicted_spread' => (float) $spread, 'actual_spread' => $margin,
            'predicted_total' => is_numeric($total) && is_finite((float) $total) ? (float) $total : null,
            'actual_total' => (float) $game->home_score + (float) $game->away_score,
            'winner_correct' => ($margin > 0 && $spread > 0) || ($margin < 0 && $spread < 0),
            'epa_applied' => $this->application($metadata, true)];
    }

    private function application(mixed $metadata, bool $strict): ?bool
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $epa = is_array($metadata) ? ($metadata['true_epa'] ?? null) : null;
        if (is_array($epa)) {
            foreach (['true_epa_applied', 'applied'] as $key) {
                if (array_key_exists($key, $epa)) {
                    return $strict ? (is_bool($epa[$key]) ? $epa[$key] : null) : (bool) $epa[$key];
                }
            }
        }

        return $strict ? null : false;
    }
}
