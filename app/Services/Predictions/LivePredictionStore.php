<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\LivePregameForecast;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game as CfbGame;
use App\Models\LivePredictionSnapshot;
use App\Services\Api\V2\SportContextResolver;
use App\Services\CFB\Live\LiveSnapshotRecorder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class LivePredictionStore
{
    private const ACTIVE_STATUSES = ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_SUSPENDED', 'STATUS_DELAYED', 'in_progress'];

    public const EMPTY_FIELDS = [
        'live_predicted_spread' => null, 'live_predicted_total' => null, 'live_win_probability' => null,
        'live_seconds_remaining' => null, 'live_outs_remaining' => null, 'live_updated_at' => null,
    ];

    public function __construct(private readonly PredictionDeliverySource $predictions, private readonly SportContextResolver $sports) {}

    public function sport(Model $game): string
    {
        return $this->sports->all()->first(fn ($context) => ($context->models['game'] ?? null) === $game::class)?->slug
            ?? throw new \InvalidArgumentException('Unsupported live game model.');
    }

    public function baseline(Model $game): ?LivePregameForecast
    {
        $sport = $this->sport($game);
        $snapshot = $this->latest($game);
        $baseline = $snapshot?->baseline;
        if (is_array($baseline) && ($baseline['pinned'] ?? false) && $this->selected($baseline, $sport, $snapshot)) {
            return LivePregameForecast::fromArray($baseline);
        }
        $context = $this->predictions->forGame($game, $sport);
        if ($context === null) {
            return null;
        }

        return new LivePregameForecast($context->predictedSpread, $context->predictedTotal, $context->homeWinProbability, [
            'prediction_source' => $context->isCanonical() ? 'canonical' : 'legacy',
            'prediction_table' => $context->source->getTable(), 'prediction_id' => $context->source->getKey(),
            'canonical_prediction_id' => $context->isCanonical() ? $context->source->getKey() : null,
            'canonical_prediction_public_id' => $context->isCanonical() ? $context->source->public_id : null,
            'calculation_run_id' => $context->isCanonical() ? $context->source->calculation_run_id : null,
        ]);
    }

    private function selected(array $baseline, string $sport, ?LivePredictionSnapshot $snapshot = null): bool
    {
        $canonical = $this->predictions->usesCanonical($sport);
        if (($baseline['prediction_source'] ?? null) !== ($canonical ? 'canonical' : 'legacy')) {
            return false;
        }

        if ($snapshot?->relationLoaded('baselineValidity')) {
            return (bool) $snapshot->getRelation('baselineValidity');
        }

        return ! $canonical || CanonicalPrediction::query()->whereKey($baseline['canonical_prediction_id'] ?? null)
            ->where('sport', $sport)->whereIn('publication_state', ['published', 'superseded'])->exists();
    }

    public function latest(Model $game): ?LivePredictionSnapshot
    {
        if ($game->relationLoaded('livePredictionSnapshot')) {
            return $game->getRelation('livePredictionSnapshot');
        }
        $snapshot = LivePredictionSnapshot::query()->where('sport', $this->sport($game))
            ->where('game_table', $game->getTable())->where('game_id', $game->getKey())
            ->where('observed_at', '<=', now())->orderByDesc('observed_at')->orderByDesc('id')->first();
        $game->setRelation('livePredictionSnapshot', $snapshot);

        return $snapshot;
    }

    /** Attach one snapshot per game with a single bounded query for a sport. */
    public function preload(Collection $games): void
    {
        $games = $games->filter(fn (Model $game) => in_array($game->status, self::ACTIVE_STATUSES, true));
        if ($games->isEmpty()) {
            return;
        }
        $first = $games->first();
        if ($first instanceof CfbGame) {
            (new \Illuminate\Database\Eloquent\Collection($games->all()))->loadMissing('latestLiveSnapshot');

            return;
        }
        $snapshots = LivePredictionSnapshot::query()->where('sport', $this->sport($first))
            ->where('game_table', $first->getTable())->whereIn('game_id', $games->pluck('id')->all())
            ->where('id', LivePredictionSnapshot::query()->from('live_prediction_snapshots as recent')->select('recent.id')
                ->whereColumn('recent.game_id', 'live_prediction_snapshots.game_id')
                ->whereColumn('recent.sport', 'live_prediction_snapshots.sport')
                ->whereColumn('recent.game_table', 'live_prediction_snapshots.game_table')
                ->where('recent.observed_at', '<=', now())->orderByDesc('recent.observed_at')->orderByDesc('recent.id')->limit(1))
            ->get()->keyBy('game_id');
        $canonicalIds = $snapshots->pluck('canonical_prediction_id')->filter()->unique();
        $validIds = $canonicalIds->isNotEmpty() ? CanonicalPrediction::query()->whereIn('id', $canonicalIds)
            ->where('sport', $this->sport($first))->whereIn('publication_state', ['published', 'superseded'])->pluck('id')->all() : [];
        foreach ($snapshots as $snapshot) {
            $snapshot->setRelation('baselineValidity', $snapshot->canonical_prediction_id === null || in_array($snapshot->canonical_prediction_id, $validIds, true));
        }
        foreach ($games as $game) {
            $game->setRelation('livePredictionSnapshot', $snapshots->get($game->getKey()));
        }
    }

    public function record(Model $game, ?LivePregameForecast $baseline, ?array $projection, string $status, string $calculator, ?CarbonInterface $observedAt = null): LivePredictionSnapshot
    {
        $observedAt ??= $game->isDirty() ? now() : ($game->updated_at ?? now());
        $state = array_intersect_key($game->getAttributes(), array_flip([
            'status', 'period', 'game_clock', 'home_score', 'away_score', 'inning', 'inning_state', 'inning_half', 'balls', 'strikes', 'outs',
        ]));
        $data = ['sport' => $this->sport($game), 'game_table' => $game->getTable(), 'game_id' => $game->getKey(),
            'canonical_prediction_id' => $baseline?->lineage['canonical_prediction_id'] ?? null,
            'baseline' => $baseline !== null ? [...$baseline->toArray(), 'pinned' => $status === 'live' || ($baseline->lineage['pinned'] ?? false)] : null, 'state' => $state, 'projection' => $projection,
            'status' => $status, 'calculator' => $calculator, 'observed_at' => $observedAt];
        $hash = app(CanonicalPayloadHasher::class)->hash($data);
        $snapshot = LivePredictionSnapshot::query()->firstOrCreate(['state_hash' => $hash], $data);
        $game->unsetRelation('livePredictionSnapshot');

        return $snapshot;
    }

    public function fields(Model $game): array
    {
        if (! in_array($game->status, self::ACTIVE_STATUSES, true)) {
            return self::EMPTY_FIELDS;
        }
        if ($game instanceof CfbGame) {
            $snapshot = $game->latestLiveSnapshot;
            if ($snapshot?->status !== 'live' || $snapshot->observed_at?->isFuture()) {
                return self::EMPTY_FIELDS;
            }
            $projection = (array) $snapshot->projection;

            return $this->normalize([...self::EMPTY_FIELDS, 'live_predicted_spread' => $projection['spread'] ?? null,
                'live_predicted_total' => $projection['total'] ?? null, 'live_win_probability' => $projection['home_win_probability'] ?? null,
                'live_seconds_remaining' => $projection['seconds_remaining'] ?? null, 'live_updated_at' => $snapshot->observed_at]);
        }
        $snapshot = $this->latest($game);
        if ($snapshot?->status !== 'live' || ! $this->selected((array) $snapshot->baseline, $this->sport($game), $snapshot)) {
            return self::EMPTY_FIELDS;
        }

        return $this->normalize([...self::EMPTY_FIELDS, ...(array) $snapshot->projection, 'live_updated_at' => $snapshot->observed_at]);
    }

    private function normalize(array $fields): array
    {
        foreach (['live_predicted_spread', 'live_predicted_total', 'live_win_probability'] as $key) {
            $fields[$key] = is_numeric($fields[$key]) ? (float) $fields[$key] : null;
        }
        foreach (['live_seconds_remaining', 'live_outs_remaining'] as $key) {
            $fields[$key] = is_numeric($fields[$key]) ? (int) $fields[$key] : null;
        }

        return $fields;
    }

    public function forPrediction(Model $prediction, string $sport): array
    {
        if ($prediction instanceof CanonicalPrediction) {
            $prediction->loadMissing('sportEvent.'.$sport.'Game');
        }
        $game = $prediction instanceof CanonicalPrediction
            ? $prediction->sportEvent?->getRelation($sport.'Game') : $prediction->getRelationValue('game');

        if (! $game instanceof Model || ! in_array($game->status, self::ACTIVE_STATUSES, true)) {
            return self::EMPTY_FIELDS;
        }

        if ($game instanceof Model && $prediction instanceof CanonicalPrediction && ! $game instanceof CfbGame
            && $this->latest($game)?->canonical_prediction_id !== $prediction->getKey()) {
            return self::EMPTY_FIELDS;
        }

        return $this->fields($game);
    }

    public function gameFor(object $subject): Model
    {
        if ($subject instanceof Model && $this->sports->all()->contains(fn ($context) => ($context->models['game'] ?? null) === $subject::class)) {
            return $subject;
        }
        $game = $subject instanceof Model ? $subject->getRelationValue('game') : null;

        return $game instanceof Model ? $game : throw new \InvalidArgumentException('Live prediction clearing requires a game.');
    }

    public function clear(Model $game, string $calculator): void
    {
        if ($game instanceof CfbGame) {
            $recorder = app(LiveSnapshotRecorder::class);
            $recorder->record($game, $recorder->baseline($game), null, 'inactive');

            return;
        }
        $latest = $this->latest($game);
        if ($latest === null || $latest->status === 'inactive') {
            return;
        }

        $this->record($game, $this->baseline($game), null, 'inactive', $calculator);
    }
}
