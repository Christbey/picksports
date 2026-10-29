<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\BettingForecast;
use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BettingForecastSource
{
    public function __construct(
        private readonly PredictionDeliverySource $delivery,
        private readonly CanonicalSportPredictionQuery $canonical,
        private readonly SportContextResolver $sports,
    ) {}

    public function usesCanonical(string $sport): bool
    {
        return $this->delivery->usesCanonical($sport);
    }

    /** @param list<int> $gameIds @return Collection<int, BettingForecast> */
    public function forGames(string $sport, array $gameIds, ?int $season = null, bool $live = false): Collection
    {
        if ($gameIds === []) {
            return collect();
        }

        if ($this->usesCanonical($sport)) {
            $query = $this->canonical->queryForSport($sport, $season !== null ? ['season' => $season] : [])
                ->whereIn("{$sport}_games.id", $gameIds);
        } else {
            $model = $this->sports->resolve($sport)->models['prediction'];
            $query = $model::query()->with(['game.homeTeam', 'game.awayTeam'])
                ->whereIn('game_id', $gameIds);
            if ($sport === 'mlb' && $season !== null) {
                $query->where('season', $season);
            }
            if ($sport === 'nfl') {
                $query->orderByDesc('updated_at')->orderByDesc('id');
            }
        }

        $models = $query->get();
        $games = $models->map(fn (Model $model) => $model instanceof CanonicalPrediction
            ? $model->sportEvent?->getRelation($sport.'Game') : $model->getRelationValue('game'))->filter();
        app(LivePredictionStore::class)->preload($games);

        return $models->map(fn (Model $model): BettingForecast => $this->map($model, $sport))
            ->filter(fn (BettingForecast $forecast): bool => $forecast->game !== null)->values();
    }

    /** @param Collection<int, Model> $games @return Collection<int, BettingForecast> */
    public function historyForGames(Collection $games, string $sport): Collection
    {
        if ($games->isEmpty()) {
            return collect();
        }
        if ($this->usesCanonical($sport)) {
            $byEvent = $games->keyBy('sport_event_id');

            return $this->canonical->queryForSport($sport)
                ->whereIn("{$sport}_games.id", $games->pluck('id')->all())
                ->withOnly([
                    'calculationRun:id,event_input_snapshot_id',
                    'calculationRun.inputSnapshot' => fn ($query) => $query->select([
                        'id', 'inputs->pregame_market->available as market_available',
                        'inputs->pregame_market->consensus->spread->line as market_spread',
                        'inputs->pregame_market->consensus->total->line as market_total',
                    ]),
                ])->get()->mapWithKeys(function (CanonicalPrediction $prediction) use ($byEvent): array {
                    $game = $byEvent->get($prediction->sport_event_id);
                    $snapshot = $prediction->calculationRun?->inputSnapshot;
                    $available = in_array($snapshot?->getAttribute('market_available'), [true, 1, '1', 'true'], true);
                    $line = $available ? $this->number($snapshot?->getAttribute('market_spread')) : null;

                    return $game !== null ? [$game->getKey() => new BettingForecast(
                        source: $prediction, game: $game, vegasSpread: $line,
                        marketHomeMargin: $line !== null ? -$line : null,
                        marketTotal: $available ? $this->number($snapshot?->getAttribute('market_total')) : null,
                    )] : [];
                });
        }
        $model = $this->sports->resolve($sport)->models['prediction'];
        $query = $model::query()->whereIn('game_id', $games->pluck('id')->all());
        if ($sport === 'nfl') {
            $query->select(['id', 'game_id',
                'model_metadata->analysis_layer->calculated_edge->market_spread as signal_market_spread',
                'model_metadata->analysis_layer->calculated_edge->market_total as signal_market_total']);
        }
        $byId = $games->keyBy('id');

        return $query->get()->mapWithKeys(fn (Model $prediction): array => [
            $prediction->game_id => $this->legacy($prediction, $byId->get($prediction->game_id)),
        ]);
    }

    public function forGame(Model $game, string $sport): ?BettingForecast
    {
        if ($this->usesCanonical($sport)) {
            $context = $this->delivery->forGame($game, $sport);

            return $context !== null ? $this->map($context->source, $sport) : null;
        }
        $model = $game->loadMissing('prediction')->getRelation('prediction');

        return $model instanceof Model ? $this->legacy($model, $game) : null;
    }

    /** Normalize an explicitly supplied record without substituting another prediction identity. */
    public function fromModel(Model $model, string $sport): BettingForecast
    {
        $expectedModel = $this->sports->resolve($sport)->models['prediction'];
        if (! $model instanceof CanonicalPrediction && ! $model instanceof $expectedModel) {
            throw new \InvalidArgumentException('Prediction sport does not match the betting forecast source.');
        }
        if ($model instanceof CanonicalPrediction
            && ! $this->canonical->queryForSport($sport)->whereKey($model->getKey())->exists()) {
            return new BettingForecast($model, null);
        }

        return $this->map($model, $sport);
    }

    /** @param list<BettingForecast> $forecasts @return list<array<string, mixed>> */
    public function approvedSignalRows(array $forecasts): array
    {
        $rows = [];
        foreach ($forecasts as $forecast) {
            if (! $forecast->isCanonical() || $forecast->game === null) {
                continue;
            }
            $context = $this->delivery->context($forecast->source, $forecast->source->sport, $forecast->game);
            foreach (app(GameBettingRecommendationService::class)->forPrediction($context) as $recommendation) {
                $side = $recommendation['pick_side'];
                $game = $forecast->game;
                $team = $side === 'home' ? $game->homeTeam : ($side === 'away' ? $game->awayTeam : null);
                $isMlbRunLine = $forecast->source->sport === 'mlb' && $recommendation['type'] === 'spread';
                $rows[] = [
                    ...$recommendation,
                    ...($isMlbRunLine ? [
                        'type' => 'run_line', 'model_line' => $forecast->predictedSpread,
                        'market_line' => $recommendation['market_home_line'] !== null ? -$recommendation['market_home_line'] : null,
                    ] : []),
                    'game_id' => (int) $game->getKey(), 'game_date' => $game->game_date?->toDateString(),
                    'matchup' => (string) ($game->short_name ?: $game->name),
                    'team_id' => $team?->getKey(), 'team_name' => $team?->display_name ?? $team?->name,
                    'classification' => 'bet', 'score' => $recommendation['score'] ?? null,
                    'edge_points' => $recommendation['type'] !== 'moneyline' ? $recommendation['edge'] : null,
                    'probability_edge' => $recommendation['type'] === 'moneyline' && $recommendation['edge'] !== null ? $recommendation['edge'] / 100 : null,
                    'market_price' => $recommendation['odds'],
                    'predicted_spread' => $forecast->predictedSpread, 'predicted_total' => $forecast->predictedTotal,
                ];
            }
        }

        return $rows;
    }

    private function map(Model $model, string $sport): BettingForecast
    {
        if (! $model instanceof CanonicalPrediction) {
            return $this->legacy($model);
        }
        $context = $this->delivery->context($model, $sport);
        $inputs = (array) ($model->calculationRun?->inputSnapshot?->inputs ?? []);
        $market = data_get($inputs, 'pregame_market.available') === true ? (array) data_get($inputs, 'pregame_market.consensus', []) : [];
        $homeBookmakerLine = $this->number(data_get($market, 'spread.line'));
        $live = app(LivePredictionStore::class)->fields($context->game);

        return new BettingForecast(
            source: $model,
            game: $context->homeWinProbability !== null && $context->predictedSpread !== null && $context->predictedTotal !== null ? $context->game : null,
            predictedSpread: $context->predictedSpread,
            predictedTotal: $context->predictedTotal,
            winProbability: $context->homeWinProbability,
            confidenceScore: is_numeric($context->confidenceScore) ? (float) $context->confidenceScore : null,
            modelMetadata: (array) ($model->output_metadata ?? []),
            vegasSpread: $homeBookmakerLine,
            marketHomeMargin: $homeBookmakerLine !== null ? -$homeBookmakerLine : null,
            marketTotal: $this->number(data_get($market, 'total.line')),
            homePitcherElo: $this->number(data_get($inputs, 'pitching.home.elo')),
            awayPitcherElo: $this->number(data_get($inputs, 'pitching.away.elo')),
            homeTeamElo: $this->number(data_get($inputs, 'home.elo')),
            awayTeamElo: $this->number(data_get($inputs, 'away.elo')),
            livePredictedSpread: $this->number($live['live_predicted_spread']),
            livePredictedTotal: $this->number($live['live_predicted_total']),
            liveWinProbability: $this->number($live['live_win_probability']),
            liveOutsRemaining: $this->number($live['live_outs_remaining']),
            liveUpdatedAt: $live['live_updated_at'],
        );
    }

    private function legacy(Model $model, ?Model $game = null): BettingForecast
    {
        $metadata = (array) ($model->model_metadata ?? []);
        $game ??= $model->getRelationValue('game');
        $live = $game instanceof Model ? app(LivePredictionStore::class)->fields($game) : LivePredictionStore::EMPTY_FIELDS;
        foreach (['signal_market_spread' => 'market_spread', 'signal_market_total' => 'market_total'] as $attribute => $key) {
            if (is_numeric($model->getAttribute($attribute))) {
                data_set($metadata, 'analysis_layer.calculated_edge.'.$key, (float) $model->getAttribute($attribute));
            }
        }

        return new BettingForecast(
            source: $model,
            game: $game ?? $model->getRelationValue('game'),
            predictedSpread: $this->number($model->getAttribute('predicted_spread')),
            predictedTotal: $this->number($model->getAttribute('predicted_total')),
            winProbability: $this->number($model->getAttribute('win_probability')),
            confidenceScore: $this->number($model->getAttribute('confidence_score')),
            modelMetadata: $metadata,
            marketHomeMargin: $this->number(data_get($metadata, 'analysis_layer.calculated_edge.market_spread')),
            marketTotal: $this->number(data_get($metadata, 'analysis_layer.calculated_edge.market_total')),
            vegasSpread: $this->number($model->getAttribute('vegas_spread')),
            homePitcherElo: $this->number($model->getAttribute('home_pitcher_elo')),
            awayPitcherElo: $this->number($model->getAttribute('away_pitcher_elo')),
            homeTeamElo: $this->number($model->getAttribute('home_team_elo')),
            awayTeamElo: $this->number($model->getAttribute('away_team_elo')),
            restDaysHome: $this->number($model->getAttribute('rest_days_home')),
            restDaysAway: $this->number($model->getAttribute('rest_days_away')),
            homeAwaySplitAdjustment: $this->number($model->getAttribute('home_away_split_adj')),
            turnoverDifferentialAdjustment: $this->number($model->getAttribute('turnover_diff_adj')),
            reboundMarginAdjustment: $this->number($model->getAttribute('rebound_margin_adj')),
            injurySpreadAdjustment: $this->number($model->getAttribute('injury_spread_adj')),
            homeInjuriesQuestionable: $this->number($model->getAttribute('home_injuries_questionable')),
            awayInjuriesQuestionable: $this->number($model->getAttribute('away_injuries_questionable')),
            livePredictedSpread: $this->number($live['live_predicted_spread']),
            livePredictedTotal: $this->number($live['live_predicted_total']),
            liveWinProbability: $this->number($live['live_win_probability']),
            liveOutsRemaining: $this->number($live['live_outs_remaining']),
            liveUpdatedAt: $live['live_updated_at'],
        );
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
