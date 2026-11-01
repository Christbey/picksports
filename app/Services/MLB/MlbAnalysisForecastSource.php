<?php

namespace App\Services\MLB;

use App\Application\Sports\ReadModels\BettingForecast;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MlbAnalysisForecastSource
{
    /** @return Collection<int, array{forecast:BettingForecast,lineage:array,prices:array{home:?int,away:?int}}> */
    public function rows(?int $season, ?string $from, ?string $to, ?string $schema, int $limit): Collection
    {
        $rows = collect();
        app(CanonicalSportPredictionQuery::class)->queryForSport('mlb', $season !== null ? ['season' => $season] : [])
            ->where('mlb_games.status', 'STATUS_FINAL')->whereNotNull('mlb_games.home_score')->whereNotNull('mlb_games.away_score')
            ->where('predictions.generated_at', '<=', now())->where('predictions.published_at', '<=', now())
            ->when($from, fn ($query) => $query->whereDate('mlb_games.game_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('mlb_games.game_date', '<=', $to))
            ->when($schema, fn ($query) => $query->whereHas('calculationRun.inputSnapshot', fn ($query) => $query->where('schema_version', $schema)))
            ->withOnly(['markets', 'calculationRun.inputSnapshot', 'calculationRun.release', 'sportEvent:id,starts_at', 'sportEvent.mlbGame:id,sport_event_id,season,status,home_team_id,away_team_id,game_date,game_time,name,short_name,home_score,away_score', 'sportEvent.mlbGame.homeTeam', 'sportEvent.mlbGame.awayTeam'])
            ->reorder()->orderByDesc('predictions.generated_at')->orderByDesc('predictions.id')
            ->chunk(25, function (Collection $predictions) use ($rows, $limit): bool {
                foreach ($predictions as $prediction) {
                    $safe = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
                    $input = $prediction->calculationRun?->inputSnapshot;
                    $start = data_get($input?->inputs, 'event.starts_at');
                    try {
                        $matches = is_string($start) && CarbonImmutable::parse($start)->eq($prediction->sportEvent?->starts_at);
                    } catch (\Throwable) {
                        $matches = false;
                    }
                    if (! $safe || ! $matches || ! $prediction->published_at->lt($safe->game_start_at)
                        || $input->latest_source_available_at?->gt($prediction->generated_at)) {
                        continue;
                    }
                    $probability = $this->number($safe->outputs['win_probability'] ?? null);
                    $spread = $this->number($safe->outputs['predicted_spread'] ?? null);
                    $total = $this->number($safe->outputs['predicted_total'] ?? null);
                    $confidence = $this->number($prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')?->confidence_score);
                    if ($probability === null || $probability < 0 || $probability > 1 || $spread === null || $total === null || $confidence === null || $confidence < 0 || $confidence > 100) {
                        continue;
                    }
                    $rows->push(['forecast' => new BettingForecast(source: $prediction, game: $prediction->sportEvent->mlbGame,
                        predictedSpread: $spread, predictedTotal: $total, winProbability: $probability, confidenceScore: $confidence,
                        modelMetadata: (array) $prediction->output_metadata, vegasSpread: $this->number($safe->market_context['bookmaker_home_line']),
                        marketHomeMargin: $safe->market_context['market_home_margin'], marketTotal: $this->number($safe->market_context['market_total'])), 'lineage' => $safe->lineage, 'prices' => $this->prices($input->inputs)]);
                    if ($rows->count() >= max(1, $limit)) {
                        return false;
                    }
                }

                return true;
            });

        return $rows->sortByDesc(fn ($row) => [$row['forecast']->source->generated_at->timestamp, $row['forecast']->source->id])->take(max(1, $limit))->values();
    }

    /** @return array{home:?int,away:?int} */
    private function prices(array $inputs): array
    {
        $quote = data_get($inputs, 'pregame_market.available') === true ? (array) data_get($inputs, 'pregame_market.consensus.moneyline', []) : [];
        $home = $this->number($quote['price'] ?? null);
        $away = $this->number(data_get($quote, 'opposite_quote.price'));
        $paired = ($quote['market_key'] ?? null) === 'h2h' && ($quote['side'] ?? null) === 'home'
            && data_get($quote, 'opposite_quote.market_key') === 'h2h' && data_get($quote, 'opposite_quote.side') === 'away'
            && $home !== null && $away !== null && abs($home) >= 100 && abs($away) >= 100;

        return ['home' => $paired ? (int) $home : null, 'away' => $paired ? (int) $away : null];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
