<?php

namespace App\Services\Predictions\Basketball;

use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BasketballBacktestForecastSource
{
    public function name(string $sport, ?string $requested = null): string
    {
        $this->validateSport($sport);
        $source = $requested ?? ((config("prediction_lifecycle.canonical_pipeline.{$sport}") || config("prediction_lifecycle.canonical_reads.{$sport}")) ? 'canonical' : 'legacy');
        if (! in_array($source, ['canonical', 'legacy'], true)) {
            throw new \InvalidArgumentException('Forecast source must be canonical or legacy.');
        }

        return $source;
    }

    /** @return Collection<int, object> */
    public function forFinalGames(string $sport, ?int $season = null, ?int $seasonType = null): Collection
    {
        return $this->finalGames($sport, $season, $seasonType);
    }

    /** @return Collection<int, object> */
    public function forCbbPlayReplay(?int $season, int $limit): Collection
    {
        return $this->finalGames('cbb', $season, null, max(1, $limit));
    }

    private function finalGames(string $sport, ?int $season, ?int $seasonType, ?int $replayLimit = null): Collection
    {
        $this->validateSport($sport);
        $rows = collect();
        $filters = array_filter(['season' => $season, 'season_type' => $seasonType === null ? null : (string) $seasonType], fn ($value) => $value !== null);
        app(CanonicalSportPredictionQuery::class)->queryForSport($sport, $filters)
            ->where("{$sport}_games.status", 'STATUS_FINAL')->whereNotNull("{$sport}_games.home_score")->whereNotNull("{$sport}_games.away_score")
            ->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->when($replayLimit !== null, fn ($query) => $query->whereHas('sportEvent.cbbGame.plays')->reorder()->orderByDesc('cbb_games.game_date')->orderByDesc('cbb_games.id'))
            ->withOnly(['markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
                'calculationRun.inputSnapshot', 'calculationRun.release:id,status', 'sportEvent:id,starts_at',
                "sportEvent.{$sport}Game:id,sport_event_id,season,season_type,home_team_id,away_team_id,home_score,away_score,game_date,game_time",
                "sportEvent.{$sport}Game.homeTeam:id,abbreviation,".($sport === 'cbb' ? 'school' : ($sport === 'wnba' ? 'location,name' : 'location')), "sportEvent.{$sport}Game.awayTeam:id,abbreviation,".($sport === 'cbb' ? 'school' : ($sport === 'wnba' ? 'location,name' : 'location'))])
            ->chunk(25, function (Collection $predictions) use ($rows, $sport, $replayLimit): bool {
                foreach ($predictions as $prediction) {
                    $row = $this->canonical($prediction, $sport);
                    if ($row !== null && $replayLimit !== null && ($row->predicted_spread === null || $row->predicted_total === null
                        || $row->win_probability === null || $row->win_probability < 0 || $row->win_probability > 1)) {
                        continue;
                    }
                    if ($row !== null && ! $rows->has($row->game->id)) {
                        $rows->put($row->game->id, $row);
                        if ($replayLimit !== null && $rows->count() >= $replayLimit) {
                            return false;
                        }
                    }
                }

                return true;
            });

        return $rows->values();
    }

    private function canonical(CanonicalPrediction $prediction, string $sport): ?object
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
            'game' => $prediction->sportEvent->{$sport.'Game'}, 'canonical_prediction_id' => $prediction->id,
            'canonical_lineage' => $snapshot->lineage, 'generated_at' => $prediction->generated_at,
            'game_start_at' => $snapshot->game_start_at,
            'predicted_spread' => $this->number($snapshot->outputs['predicted_spread'] ?? null),
            'predicted_total' => $this->number($snapshot->outputs['predicted_total'] ?? null),
            'win_probability' => $this->number($moneyline?->probability),
            'confidence_score' => $this->number($moneyline?->confidence_score),
            'market_sportsbook_spread' => $homeLine, 'market_total' => $this->number(data_get($market, 'total.line')),
        ];
    }

    private function validateSport(string $sport): void
    {
        if (! in_array($sport, ['nba', 'cbb', 'wnba'], true)) {
            throw new \InvalidArgumentException('Backtest sport must be nba, cbb or wnba.');
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
