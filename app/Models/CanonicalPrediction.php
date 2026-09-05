<?php

namespace App\Models;

use App\Support\SportCatalog;
use Database\Factories\CanonicalPredictionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;
use LogicException;

class CanonicalPrediction extends Model
{
    /** @use HasFactory<CanonicalPredictionFactory> */
    use HasFactory, HasUlids;

    public const DETAIL_SOURCE_LEGACY_SPORT_PREDICTION = 'legacy_sport_prediction';

    public const PHASES = ['pregame', 'live'];

    public const PUBLICATION_STATES = ['draft', 'published', 'superseded', 'withdrawn', 'legacy'];

    protected $table = 'predictions';

    protected $fillable = [
        'public_id',
        'sport_event_id',
        'calculation_run_id',
        'feature_schema_id',
        'dataset_export_manifest_id',
        'model_run_id',
        'model_artifact_id',
        'sport',
        'revision',
        'supersedes_prediction_id',
        'phase',
        'publication_state',
        'output_hash',
        'output_metadata',
        'detail_source',
        'detail_sport',
        'detail_id',
        'status',
        'model_version',
        'feature_version',
        'blend_version',
        'generated_at',
        'published_at',
        'withdrawn_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'detail_id' => 'integer',
            'revision' => 'integer',
            'output_metadata' => 'array',
            'generated_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $prediction): void {
            if ($prediction->getOriginal('calculation_run_id') === null) {
                return;
            }

            $originalState = (string) $prediction->getOriginal('publication_state');

            if (in_array($originalState, ['superseded', 'withdrawn'], true)) {
                if (array_diff(array_keys($prediction->getDirty()), ['updated_at']) !== []) {
                    throw new LogicException('Released canonical prediction revisions are immutable.');
                }

                return;
            }

            if ($originalState !== 'published') {
                return;
            }

            $allowed = [
                'publication_state',
                'withdrawn_at',
                'superseded_at',
                'updated_at',
            ];

            if (array_diff(array_keys($prediction->getDirty()), $allowed) !== []) {
                throw new LogicException('Published canonical prediction revisions are immutable.');
            }

            if ($prediction->isDirty('publication_state')) {
                $nextState = (string) $prediction->publication_state;

                if (! in_array($nextState, ['superseded', 'withdrawn'], true)) {
                    throw new LogicException("Invalid canonical prediction transition from published to {$nextState}.");
                }

                $transitionTimestamp = $nextState === 'superseded'
                    ? $prediction->superseded_at
                    : $prediction->withdrawn_at;

                if ($transitionTimestamp === null) {
                    throw new LogicException("A {$nextState} canonical prediction requires its transition timestamp.");
                }
            }

            if ($prediction->isDirty('superseded_at') && $prediction->publication_state !== 'superseded') {
                throw new LogicException('superseded_at requires a superseded canonical prediction.');
            }

            if ($prediction->isDirty('withdrawn_at') && $prediction->publication_state !== 'withdrawn') {
                throw new LogicException('withdrawn_at requires a withdrawn canonical prediction.');
            }
        });

        static::deleting(function (self $prediction): void {
            if ($prediction->calculation_run_id !== null) {
                throw new LogicException('Canonical prediction revisions cannot be deleted.');
            }
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function setSportAttribute(string $sport): void
    {
        $this->attributes['sport'] = $this->validatedSport($sport);
    }

    public function setPhaseAttribute(string $phase): void
    {
        $phase = strtolower(trim($phase));

        if (! in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException('Unsupported canonical prediction phase.');
        }

        $this->attributes['phase'] = $phase;
    }

    public function setPublicationStateAttribute(string $state): void
    {
        $state = strtolower(trim($state));

        if (! in_array($state, self::PUBLICATION_STATES, true)) {
            throw new InvalidArgumentException('Unsupported canonical prediction publication state.');
        }

        $this->attributes['publication_state'] = $state;
    }

    public function setDetailSportAttribute(?string $sport): void
    {
        if ($sport === null) {
            $this->attributes['detail_sport'] = null;

            return;
        }

        $this->attributes['detail_sport'] = $this->validatedSport($sport);
    }

    public function setDetailSourceAttribute(?string $source): void
    {
        if ($source === null) {
            $this->attributes['detail_source'] = null;

            return;
        }

        if ($source !== self::DETAIL_SOURCE_LEGACY_SPORT_PREDICTION) {
            throw new InvalidArgumentException('Unsupported canonical prediction detail source.');
        }

        $this->attributes['detail_source'] = $source;
    }

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
    }

    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_prediction_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_prediction_id');
    }

    public function featureSchema(): BelongsTo
    {
        return $this->belongsTo(FeatureSchema::class);
    }

    public function datasetExportManifest(): BelongsTo
    {
        return $this->belongsTo(DatasetExportManifest::class);
    }

    public function modelRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class);
    }

    public function modelArtifact(): BelongsTo
    {
        return $this->belongsTo(ModelArtifact::class);
    }

    public function markets(): HasMany
    {
        return $this->hasMany(PredictionMarket::class, 'prediction_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(PredictionEvaluation::class);
    }

    public function latestEvaluation(): HasOne
    {
        return $this->hasOne(PredictionEvaluation::class)->latestOfMany('evaluation_revision');
    }

    /** @param Builder<self> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('publication_state', 'published');
    }

    private function validatedSport(string $sport): string
    {
        $sport = strtolower(trim($sport));

        if (! in_array($sport, SportCatalog::ALL, true)) {
            throw new InvalidArgumentException('Unsupported canonical prediction sport.');
        }

        return $sport;
    }
}
