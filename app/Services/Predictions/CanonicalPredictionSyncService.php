<?php

namespace App\Services\Predictions;

use App\Models\CanonicalPrediction;
use App\Models\CBB\Prediction as CbbPrediction;
use App\Models\CFB\Prediction as CfbPrediction;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\NBA\Prediction as NbaPrediction;
use App\Models\NFL\Prediction as NflPrediction;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\WCBB\Prediction as WcbbPrediction;
use App\Models\WNBA\Prediction as WnbaPrediction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CanonicalPredictionSyncService
{
    public function __construct(
        private readonly CanonicalPredictionLineageResolver $lineage,
    ) {}

    /** @var array<string, class-string<Model>> */
    private const PREDICTION_MODELS = [
        'cbb' => CbbPrediction::class,
        'cfb' => CfbPrediction::class,
        'mlb' => MlbPrediction::class,
        'nba' => NbaPrediction::class,
        'nfl' => NflPrediction::class,
        'wcbb' => WcbbPrediction::class,
        'wnba' => WnbaPrediction::class,
    ];

    /** @return list<string> */
    public function supportedSports(): array
    {
        return array_keys(self::PREDICTION_MODELS);
    }

    /**
     * Synchronize a legacy prediction at write time.
     *
     * @return array<string, mixed>
     */
    public function syncLegacyPrediction(string $sport, Model $legacyPrediction, bool $dryRun = false): array
    {
        $sport = strtolower(trim($sport));
        $expectedModel = self::PREDICTION_MODELS[$sport] ?? null;

        if ($expectedModel === null || ! $legacyPrediction instanceof $expectedModel) {
            throw new InvalidArgumentException('Legacy prediction does not match a supported sport source.');
        }

        if ($dryRun) {
            return $this->syncOne($sport, $legacyPrediction, true);
        }

        return DB::transaction(function () use ($sport, $legacyPrediction): array {
            $locked = $legacyPrediction->newQuery()
                ->with('game')
                ->lockForUpdate()
                ->findOrFail($legacyPrediction->getKey());

            return $this->syncOne($sport, $locked, false);
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $sports
     * @return array<string, mixed>
     */
    public function backfill(
        array $sports,
        int $chunkSize = 500,
        int $limit = 0,
        bool $dryRun = false,
    ): array {
        $report = $this->emptyReport();

        foreach ($sports as $sport) {
            $modelClass = self::PREDICTION_MODELS[$sport];
            $remaining = $limit > 0 ? $limit - $report['predictions_scanned'] : null;

            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $query = $modelClass::query()->with('game')->orderBy('id');

            if ($remaining !== null) {
                $query->limit($remaining);
            }

            $query->chunkById($chunkSize, function (Collection $predictions) use (
                $sport,
                $dryRun,
                $limit,
                &$report,
            ): bool {
                foreach ($predictions as $prediction) {
                    if ($limit > 0 && $report['predictions_scanned'] >= $limit) {
                        return false;
                    }

                    $result = $this->syncLegacyPrediction($sport, $prediction, $dryRun);
                    $report['predictions_scanned']++;

                    foreach ($this->counterKeys() as $key) {
                        $report[$key] += $result[$key];
                    }

                    $report['conflict_details'] = [
                        ...$report['conflict_details'],
                        ...$result['conflict_details'],
                    ];
                }

                return true;
            });
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function syncOne(string $sport, Model $legacyPrediction, bool $dryRun): array
    {
        $result = $this->emptyResult();
        $game = $legacyPrediction->relationLoaded('game')
            ? $legacyPrediction->getRelation('game')
            : $legacyPrediction->getRelationValue('game');
        $sportEventId = $game instanceof Model ? $game->getAttribute('sport_event_id') : null;

        if (! is_numeric($sportEventId)) {
            $result['missing_events']++;

            return $result;
        }

        $event = SportEvent::query()->find((int) $sportEventId);
        if (! $event || $event->sport !== $sport) {
            return $this->conflict(
                $result,
                $sport,
                $legacyPrediction,
                $event ? 'sport_event_sport_mismatch' : 'sport_event_not_found',
            );
        }

        $detail = [
            'detail_source' => CanonicalPrediction::DETAIL_SOURCE_LEGACY_SPORT_PREDICTION,
            'detail_sport' => $sport,
            'detail_id' => $legacyPrediction->getKey(),
        ];
        $canonicalQuery = CanonicalPrediction::query()->where($detail);

        if (! $dryRun) {
            $canonicalQuery->lockForUpdate();
        }

        $canonical = $canonicalQuery->first();

        if ($canonical && ((int) $canonical->sport_event_id !== (int) $sportEventId || $canonical->sport !== $sport)) {
            return $this->conflict($result, $sport, $legacyPrediction, 'canonical_identity_mismatch');
        }

        $attributes = [
            'sport_event_id' => (int) $sportEventId,
            ...$this->lineage->resolve($sport, $legacyPrediction, ! $dryRun),
            'sport' => $sport,
            ...$detail,
            'status' => 'active',
            'model_version' => $this->stringAttribute($legacyPrediction, 'model_version'),
            'feature_version' => $this->stringAttribute($legacyPrediction, 'feature_version'),
            'blend_version' => $this->stringAttribute($legacyPrediction, 'blend_version'),
            'generated_at' => $legacyPrediction->getAttribute('updated_at'),
        ];
        $desiredMarkets = $this->desiredMarkets($legacyPrediction);

        if (! $canonical) {
            $result['predictions_created']++;
            $result['markets_created'] += count($desiredMarkets);

            if (! $dryRun) {
                $canonical = CanonicalPrediction::query()->create($attributes);
                foreach ($desiredMarkets as $market) {
                    $canonical->markets()->create($market);
                }
            }

            return $result;
        }

        $changed = false;
        $canonical->fill($attributes);
        if ($canonical->isDirty()) {
            $result['predictions_updated']++;
            $changed = true;

            if (! $dryRun) {
                $canonical->save();
            }
        }

        $existingMarkets = $canonical->markets()->get()->keyBy(
            fn (PredictionMarket $market): string => $this->marketKey($market->market_type, $market->selection),
        );

        foreach ($desiredMarkets as $marketAttributes) {
            $key = $this->marketKey($marketAttributes['market_type'], $marketAttributes['selection']);
            $market = $existingMarkets->get($key);

            if (! $market) {
                $result['markets_created']++;
                $changed = true;

                if (! $dryRun) {
                    $canonical->markets()->create($marketAttributes);
                }

                continue;
            }

            $market->fill($marketAttributes);
            if ($market->isDirty()) {
                $result['markets_updated']++;
                $changed = true;

                if (! $dryRun) {
                    $market->save();
                }
            }

            $existingMarkets->forget($key);
        }

        foreach ($existingMarkets as $staleMarket) {
            if (! $staleMarket->is_primary) {
                continue;
            }

            $result['markets_deactivated']++;
            $changed = true;

            if (! $dryRun) {
                $staleMarket->update(['is_primary' => false]);
            }
        }

        if (! $changed) {
            $result['already_synced']++;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function desiredMarkets(Model $prediction): array
    {
        $confidence = $this->numericAttribute($prediction, 'confidence_score');
        $homeProbability = $this->probability($prediction->getAttribute('win_probability'));
        $markets = [];

        if ($homeProbability !== null) {
            $markets[] = $this->market('moneyline', 'home', probability: $homeProbability, confidence: $confidence);
            $markets[] = $this->market('moneyline', 'away', probability: 1 - $homeProbability, confidence: $confidence);
        }

        $spread = $this->numericAttribute($prediction, 'predicted_spread');
        if ($spread !== null) {
            $markets[] = $this->market('spread', 'home', line: $spread, confidence: $confidence);
        }

        $total = $this->numericAttribute($prediction, 'predicted_total');
        if ($total !== null) {
            $markets[] = $this->market('total', 'combined', line: $total, confidence: $confidence);
        }

        return $markets;
    }

    /** @return array<string, mixed> */
    private function market(
        string $marketType,
        string $selection,
        ?float $line = null,
        ?float $probability = null,
        ?float $confidence = null,
    ): array {
        return [
            'market_type' => $marketType,
            'selection' => $selection,
            'projected_line' => $line,
            'probability' => $probability,
            'confidence_score' => $confidence,
            'is_primary' => true,
        ];
    }

    private function probability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $probability = (float) $value;
        $probability = $probability > 1 ? $probability / 100 : $probability;

        return max(0, min(1, $probability));
    }

    private function numericAttribute(Model $model, string $attribute): ?float
    {
        $value = $model->getAttribute($attribute);

        return is_numeric($value) ? (float) $value : null;
    }

    private function stringAttribute(Model $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function marketKey(string $marketType, string $selection): string
    {
        return $marketType.':'.$selection;
    }

    /** @return array<string, mixed> */
    private function conflict(array $result, string $sport, Model $prediction, string $reason): array
    {
        $result['conflicts']++;
        $result['conflict_details'][] = [
            'sport' => $sport,
            'detail_source' => CanonicalPrediction::DETAIL_SOURCE_LEGACY_SPORT_PREDICTION,
            'detail_id' => $prediction->getKey(),
            'reason' => $reason,
        ];

        return $result;
    }

    /** @return list<string> */
    private function counterKeys(): array
    {
        return [
            'predictions_created',
            'predictions_updated',
            'already_synced',
            'markets_created',
            'markets_updated',
            'markets_deactivated',
            'missing_events',
            'conflicts',
        ];
    }

    /** @return array<string, mixed> */
    private function emptyReport(): array
    {
        return [
            'predictions_scanned' => 0,
            ...$this->emptyResult(),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return [
            'predictions_created' => 0,
            'predictions_updated' => 0,
            'already_synced' => 0,
            'markets_created' => 0,
            'markets_updated' => 0,
            'markets_deactivated' => 0,
            'missing_events' => 0,
            'conflicts' => 0,
            'conflict_details' => [],
        ];
    }
}
