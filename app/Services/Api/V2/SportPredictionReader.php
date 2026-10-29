<?php

namespace App\Services\Api\V2;

use App\Http\Resources\Api\V2\CanonicalSportPredictionResource;
use App\Http\Resources\Api\V2\SportPredictionResource;
use App\Models\CanonicalPrediction;
use App\Services\Predictions\LivePredictionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class SportPredictionReader
{
    public function __construct(
        private readonly SportPredictionQuery $legacy,
        private readonly CanonicalSportPredictionQuery $canonical,
        private readonly SportPredictionPresentationService $legacyPresentations,
        private readonly CanonicalPredictionPresentationService $canonicalPresentations,
    ) {}

    public function queryFor(SportContext $context): SportPredictionQuery|CanonicalSportPredictionQuery
    {
        return $this->canonical->supports($context) ? $this->canonical : $this->legacy;
    }

    /** @return array{prediction_source?: string} */
    public function sourceMeta(SportContext $context): array
    {
        return $this->canonical->supports($context) ? ['prediction_source' => 'canonical'] : [];
    }

    public function resource(SportContext $context, Model $prediction): JsonResource
    {
        return $this->resources($context, collect([$prediction]))->sole();
    }

    /**
     * @param  Collection<int, Model>  $predictions
     * @return Collection<int, JsonResource>
     */
    public function resources(SportContext $context, Collection $predictions): Collection
    {
        if ($predictions->isEmpty()) {
            return collect();
        }

        if ($predictions->first() instanceof CanonicalPrediction) {
            (new \Illuminate\Database\Eloquent\Collection($predictions->all()))->loadMissing(['sportEvent.'.$context->slug.'Game.homeTeam', 'sportEvent.'.$context->slug.'Game.awayTeam']);
            app(LivePredictionStore::class)->preload($predictions->map(fn ($prediction) => $prediction->sportEvent?->getRelation($context->slug.'Game'))->filter());

            return $predictions->map(fn (CanonicalPrediction $prediction): JsonResource => new CanonicalSportPredictionResource(
                $prediction, $context, $this->canonicalPresentations->forPrediction($prediction),
            ));
        }

        $presentations = $this->legacyPresentations->forPredictions($context, $predictions);

        return $predictions->map(fn (Model $prediction): JsonResource => new SportPredictionResource(
            $prediction, $context, $presentations->get((int) $prediction->getKey()),
        ));
    }

    /** @return array{data: JsonResource, meta: array{prediction_source: string, game_id: int|null}} */
    public function forGame(SportContext $context, string $game, Request $request): array
    {
        $prediction = $this->queryFor($context)->findForGame($context, $game, $request->user());

        return $this->payload($context, $prediction);
    }

    /** @return array{data: JsonResource, meta: array{prediction_source: string, game_id: int|null}}|null */
    public function forLoadedGame(SportContext $context, Model $game): ?array
    {
        $prediction = $this->canonical->supports($context)
            ? $this->canonical->queryForSport($context->slug, ['game_id' => $game->getKey()])->first()
            : $game->loadMissing('prediction')->getRelation('prediction');

        if (! $prediction instanceof Model) {
            return null;
        }

        if (! $prediction instanceof CanonicalPrediction) {
            $prediction->setRelation('game', $game);
        }

        return $this->payload($context, $prediction);
    }

    /** @return array{data: JsonResource, meta: array{prediction_source: string, game_id: int|null}} */
    private function payload(SportContext $context, Model $prediction): array
    {
        $canonical = $prediction instanceof CanonicalPrediction;

        return [
            'data' => $this->resource($context, $prediction),
            'meta' => [
                'prediction_source' => $canonical ? 'canonical' : 'legacy',
                'game_id' => $canonical
                    ? $prediction->sportEvent?->getRelation($context->slug.'Game')?->getKey()
                    : (int) $prediction->game_id,
            ],
        ];
    }
}
