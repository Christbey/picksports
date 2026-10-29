<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\PredictionDeliveryContext;
use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PredictionDeliverySource
{
    public function __construct(
        private readonly SportContextResolver $sports,
        private readonly CanonicalSportPredictionQuery $canonical,
    ) {}

    public function usesCanonical(string $sport): bool
    {
        $context = $this->sports->find($sport);

        return $context !== null && $this->canonical->supports($context);
    }

    public function legacyWriterRetired(string $sport): bool
    {
        return $this->usesCanonical($sport)
            && (bool) config("prediction_lifecycle.canonical_pipeline.{$sport}", false);
    }

    public function assertLegacyWriterAvailable(string $sport): void
    {
        if ($this->legacyWriterRetired($sport)) {
            throw new \LogicException("Legacy pregame writes are retired for {$sport}; use canonical generation.");
        }
    }

    /** @return Collection<int, PredictionDeliveryContext> */
    public function forAlerts(string $sport): Collection
    {
        $context = $this->sports->find($sport);
        if ($context === null) {
            return collect();
        }

        $query = $this->usesCanonical($sport)
            ? $this->canonical->queryForSport($sport)
                ->where('sport_events.starts_at', '>', now())
                ->where("{$sport}_games.status", '!=', 'completed')
            : $this->legacyQuery($sport)->whereHas('game', fn (Builder $query) => $query
                ->where('game_date', '>', now())
                ->whereNotNull('odds_data')
                ->where('status', '!=', 'completed'));

        return $query->get()->map(fn (Model $prediction): PredictionDeliveryContext => $this->context($prediction, $sport));
    }

    /** @return Collection<int, PredictionDeliveryContext> */
    public function forDigest(string $sport, CarbonInterface $now): Collection
    {
        $query = $this->usesCanonical($sport)
            ? $this->canonical->queryForSport($sport, ['from_date' => $now->toDateString(), 'to_date' => $now->toDateString()])
                ->whereNotIn("{$sport}_games.status", ['STATUS_FINAL', 'final'])
            : $this->legacyQuery($sport)->whereHas('game', fn (Builder $query) => $query
                ->whereDate('game_date', $now->toDateString())
                ->whereNotIn('status', ['STATUS_FINAL', 'final']));

        return $query->limit(30)->get()
            ->map(fn (Model $prediction): PredictionDeliveryContext => $this->context($prediction, $sport));
    }

    public function forGame(Model $game, string $sport): ?PredictionDeliveryContext
    {
        $gameClass = $this->sports->resolve($sport)->models['game'];
        if (! $game instanceof $gameClass) {
            return null;
        }

        $prediction = $this->usesCanonical($sport)
            ? $this->canonical->queryForSport($sport, ['game_id' => $game->getKey()])->first()
            : $game->loadMissing('prediction')->getRelation('prediction');

        return $prediction instanceof Model ? $this->context($prediction, $sport, $game) : null;
    }

    public function isSelected(PredictionDeliveryContext $context): bool
    {
        if (! $context->isCanonical()) {
            return ! $this->usesCanonical($context->sport);
        }

        return $this->usesCanonical($context->sport)
            && $this->canonical->queryForSport($context->sport, ['game_id' => $context->game->getKey()])
                ->whereKey($context->source->getKey())->exists();
    }

    public function context(Model $prediction, string $sport, ?Model $game = null): PredictionDeliveryContext
    {
        if ($prediction instanceof CanonicalPrediction) {
            if ($prediction->sport !== $sport) {
                throw new \InvalidArgumentException('Prediction sport does not match the delivery context.');
            }
            $prediction->loadMissing('markets', 'sportEvent.'.$sport.'Game.homeTeam', 'sportEvent.'.$sport.'Game.awayTeam');
            $game ??= $prediction->sportEvent?->getRelation($sport.'Game');
            $home = $prediction->markets->first(fn ($market): bool => $market->market_type === 'moneyline' && $market->selection === 'home');
            $spread = $prediction->markets->first(fn ($market): bool => $market->market_type === 'spread' && $market->selection === 'home');
            $total = $prediction->markets->first(fn ($market): bool => $market->market_type === 'total' && $market->selection === 'combined');

            return new PredictionDeliveryContext(
                $prediction, $sport, $game,
                $spread?->projected_line !== null ? -(float) $spread->projected_line : null,
                $total?->projected_line !== null ? (float) $total->projected_line : null,
                $home?->probability !== null ? (float) $home->probability : null,
                $sport === 'cfb' ? null : $home?->confidence_score,
            );
        }

        $game ??= $prediction->getRelationValue('game');

        return new PredictionDeliveryContext(
            $prediction, $sport, $game,
            $prediction->predicted_spread !== null ? (float) $prediction->predicted_spread : null,
            $prediction->predicted_total !== null ? (float) $prediction->predicted_total : null,
            $prediction->win_probability !== null ? (float) $prediction->win_probability : null,
            $prediction->confidence_score,
        );
    }

    private function legacyQuery(string $sport): Builder
    {
        $model = $this->sports->resolve($sport)->models['prediction'];

        return $model::query()->with(['game.homeTeam', 'game.awayTeam', 'game.prediction']);
    }
}
