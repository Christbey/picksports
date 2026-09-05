<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelArtifact extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'training_run_id',
        'sport',
        'market_type',
        'model_type',
        'model_version',
        'feature_version',
        'dataset_hash',
        'dataset_path',
        'dataset_disk',
        'dataset_object_key',
        'dataset_uri',
        'dataset_size',
        'dataset_content_type',
        'artifact_path',
        'artifact_disk',
        'artifact_object_key',
        'artifact_uri',
        'artifact_hash',
        'artifact_size',
        'artifact_content_type',
        'status',
        'metrics',
        'evaluation_report_path',
        'evaluation_report_disk',
        'evaluation_report_object_key',
        'evaluation_report_uri',
        'evaluation_report_hash',
        'evaluation_report_size',
        'evaluation_report_content_type',
        'promotion_criteria',
        'promotion_decision',
        'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'dataset_size' => 'integer',
            'artifact_size' => 'integer',
            'evaluation_report_size' => 'integer',
            'promotion_criteria' => 'array',
            'promotion_decision' => 'array',
            'promoted_at' => 'datetime',
        ];
    }

    public function trainingRun(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class, 'training_run_id');
    }

    public function shadowOutputs(): HasMany
    {
        return $this->hasMany(ShadowModelOutput::class);
    }

    public function canonicalPredictions(): HasMany
    {
        return $this->hasMany(CanonicalPrediction::class);
    }

    /**
     * @return list<string>
     */
    public function promotedMarkets(): array
    {
        $markets = data_get($this->promotion_decision, 'promoted_markets');
        if (is_array($markets)) {
            return array_values(array_unique(array_filter(array_map(
                fn (mixed $market): string => self::normalizeMarketType((string) $market),
                $markets,
            ))));
        }

        if ($this->status !== 'promoted') {
            return [];
        }

        $market = self::normalizeMarketType($this->market_type);

        return $market === 'multi_market' ? [] : [$market];
    }

    public function isPromotedForMarket(string $market): bool
    {
        return $this->status === 'promoted'
            && in_array(self::normalizeMarketType($market), $this->promotedMarkets(), true);
    }

    public static function normalizeMarketType(string $market): string
    {
        return match (strtolower(trim($market))) {
            'moneyline', 'winner', 'h2h', 'win', 'win_probability' => 'win_probability',
            'spread', 'home_margin', 'margin' => 'spread',
            'total', 'totals', 'total_points', 'over_under' => 'total',
            'multi_market' => 'multi_market',
            default => strtolower(trim($market)),
        };
    }
}
