<?php

namespace App\Services\NBA;

use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class NbaBacktestForecastSource
{
    public function name(?string $requested = null): string
    {
        $source = $requested ?? ((config('prediction_lifecycle.canonical_pipeline.nba') || config('prediction_lifecycle.canonical_reads.nba')) ? 'canonical' : 'legacy');
        if (! in_array($source, ['canonical', 'legacy'], true)) {
            throw new \InvalidArgumentException('Forecast source must be canonical or legacy.');
        }

        return $source;
    }

    /** @return Collection<int, object> */
    public function forFinalGames(?int $season = null, ?int $seasonType = null): Collection
    {
        $rows = collect();
        $filters = array_filter(['season' => $season, 'season_type' => $seasonType === null ? null : (string) $seasonType], fn ($value) => $value !== null);
        app(CanonicalSportPredictionQuery::class)->queryForSport('nba', $filters)
            ->where('nba_games.status', 'STATUS_FINAL')->whereNotNull('nba_games.home_score')->whereNotNull('nba_games.away_score')
            ->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->withOnly(['markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
                'calculationRun.inputSnapshot', 'calculationRun.release:id,status', 'sportEvent:id,starts_at',
                'sportEvent.nbaGame:id,sport_event_id,season,season_type,home_team_id,away_team_id,home_score,away_score',
                'sportEvent.nbaGame.homeTeam:id,abbreviation,location', 'sportEvent.nbaGame.awayTeam:id,abbreviation,location'])
            ->chunk(25, function (Collection $predictions) use ($rows): void {
                foreach ($predictions as $prediction) {
                    $row = $this->canonical($prediction);
                    if ($row !== null && ! $rows->has($row->game->id)) {
                        $rows->put($row->game->id, $row);
                    }
                }
            });

        return $rows->values();
    }

    private function canonical(CanonicalPrediction $prediction): ?object
    {
        $snapshot = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
        $input = $prediction->calculationRun?->inputSnapshot;
        $frozen = data_get($input?->inputs, 'event.starts_at');
        try {
            $matches = is_string($frozen) && CarbonImmutable::parse($frozen)->eq($prediction->sportEvent?->starts_at);
        } catch (\Throwable) {
            $matches = false;
        }
        if (! $snapshot || ! $matches || $prediction->published_at->gte($snapshot->game_start_at)
            || ($input->latest_source_available_at && $input->latest_source_available_at->gt($prediction->generated_at))) {
            return null;
        }
        $market = data_get($input->inputs, 'pregame_market.available') === true ? (array) data_get($input->inputs, 'pregame_market.consensus', []) : [];
        $homeLine = $this->number(data_get($market, 'spread.line'));
        $moneyline = $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home');

        return (object) [
            'game' => $prediction->sportEvent->nbaGame, 'canonical_prediction_id' => $prediction->id,
            'canonical_lineage' => $snapshot->lineage, 'generated_at' => $prediction->generated_at,
            'game_start_at' => $snapshot->game_start_at,
            'predicted_spread' => $this->number($snapshot->outputs['predicted_spread'] ?? null),
            'predicted_total' => $this->number($snapshot->outputs['predicted_total'] ?? null),
            'confidence_score' => $this->number($moneyline?->confidence_score),
            'market_sportsbook_spread' => $homeLine, 'market_total' => $this->number(data_get($market, 'total.line')),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
