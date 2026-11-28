<?php

namespace App\Models;

use App\Casts\UtcEvidenceDateTime;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use WeakMap;

class ProviderRefreshAttempt extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    private static ?WeakMap $appliedWritePermits = null;

    public const OUTCOMES = ['applied', 'unavailable', 'rejected', 'superseded', 'expired', 'failed'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'observed' => 'boolean', 'reliable' => 'boolean', 'row_count' => 'integer',
            'started_at' => UtcEvidenceDateTime::class, 'expires_at' => UtcEvidenceDateTime::class, 'completed_at' => UtcEvidenceDateTime::class, 'created_at' => UtcEvidenceDateTime::class, 'updated_at' => UtcEvidenceDateTime::class];
    }

    public function freshTimestamp()
    {
        return CarbonImmutable::now('UTC');
    }

    /** Internal completion path: ordinary ORM reads never contain the plaintext acquisition owner. */
    public function completeAppliedFromOwner(string $owner, array $attributes): bool
    {
        if (! $this->exists || $this->isDirty($this->getKeyName())) {
            throw new LogicException('Refresh applied identity is invalid.');
        }

        return $this->getConnection()->transaction(function () use ($owner, $attributes): bool {
            $head = ProviderRefreshHead::on($this->getConnectionName())->whereKey($this->getRawOriginal('head_id'))->lockForUpdate()->firstOrFail();
            $persisted = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $supported = $head->component === 'injuries'
                ? in_array($head->sport, ['nfl', 'cfb', 'cbb', 'mlb', 'wnba', 'wcbb'], true) && (int) $head->season_key === 0
                : $head->component === 'depth_chart' && in_array($head->sport, ['nfl', 'nba', 'mlb'], true)
                    && (int) $head->season_key >= 1900 && (int) $head->season_key <= (int) CarbonImmutable::now('UTC')->year + 1;
            if (! $head->hasRecordedAttestations() || $persisted->outcome !== null || ! hash_equals($persisted->owner_hash, hash('sha256', $owner))
                || $persisted->sequence !== $head->issued_sequence || $persisted->expires_at->lte(CarbonImmutable::now('UTC'))
                || ! $supported || config('provider_refresh.enabled', false) !== true
                || (int) $head->protocol_version !== 1 || config('provider_refresh.protocol_version', 1) !== 1
                || $head->activated_at === null || $head->drained_at === null || $head->activated_at->gt(CarbonImmutable::now('UTC'))
                || $head->drained_at->gt($head->activated_at)) {
                throw new LogicException('Refresh applied completion requires its current unexpired admitted owner.');
            }
            $target = app(EloWriteTargetIdentity::class)->describe($this->getConnection())['fingerprint'];
            $configured = config('provider_refresh.write_target_fingerprint');
            if (! is_string($configured) || ! hash_equals($target, $configured) || ! hash_equals($target, $head->target_fingerprint)) {
                throw new LogicException('Refresh applied completion target changed.');
            }
            self::$appliedWritePermits ??= new WeakMap;
            self::$appliedWritePermits[$this] = $persisted->owner_hash;
            try {
                return $this->forceFill([...$attributes, 'outcome' => 'applied'])->save();
            } finally {
                unset(self::$appliedWritePermits[$this]);
            }
        });
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            if ($this->outcome !== null || $this->completed_at !== null) {
                throw new LogicException('Refresh attempts begin running without invented terminal history.');
            }

            if (! is_string($this->owner_hash) || preg_match('/\A[a-f0-9]{64}\z/', $this->owner_hash) !== 1
                || (int) $this->sequence < 1 || $this->started_at === null || $this->expires_at === null
                || $this->expires_at->lte($this->started_at) || $this->expires_at->diffInSeconds($this->started_at, true) > 300
                || (bool) $this->observed || (bool) $this->reliable || (int) $this->row_count !== 0
                || $this->source_fingerprint !== null || $this->error_code !== null
                || $this->nfl_injury_snapshot_id !== null || $this->nfl_depth_snapshot_id !== null) {
                throw new LogicException('Refresh attempt requires an owner and a bounded positive lease identity.');
            }

            return $this->getConnection()->transaction(function () use ($options): bool {
                $head = ProviderRefreshHead::on($this->getConnectionName())->whereKey($this->head_id)->lockForUpdate()->firstOrFail();
                if ($this->sequence !== $head->issued_sequence + 1) {
                    throw new LogicException('Refresh attempt must be the next reserved scope sequence.');
                }

                return parent::save($options);
            });
        }
        if ($this->isDirty($this->getKeyName())) {
            throw new LogicException('Refresh attempt identity is immutable.');
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $head = ProviderRefreshHead::on($this->getConnectionName())->whereKey($this->getRawOriginal('head_id'))->lockForUpdate()->firstOrFail();
            $persisted = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($persisted->outcome !== null) {
                throw new LogicException('Terminal refresh attempts are immutable.');
            }
            foreach (['head_id', 'sequence', 'owner_hash', 'team_provider_id', 'started_at', 'expires_at', 'created_at'] as $key) {
                if (($this->getAttributes()[$key] ?? null) !== ($persisted->getAttributes()[$key] ?? null)) {
                    throw new LogicException('Refresh attempt owner, lease and identity are immutable.');
                }
            }
            if (! in_array($this->outcome, self::OUTCOMES, true) || $this->completed_at === null) {
                throw new LogicException('Refresh attempt updates must record a supported terminal outcome.');
            }

            if ($this->outcome === 'applied' && (self::$appliedWritePermits === null
                || ! isset(self::$appliedWritePermits[$this]) || ! hash_equals($persisted->owner_hash, self::$appliedWritePermits[$this]))) {
                throw new LogicException('Applied refresh receipts require the private owner completion permit.');
            }
            if ($this->sequence > $head->issued_sequence || ($this->outcome === 'applied'
                && ($this->sequence !== $head->issued_sequence || $this->expires_at->lte(CarbonImmutable::now('UTC'))))) {
                throw new LogicException('Only the current unexpired refresh attempt may apply a projection.');
            }
            if (($this->outcome === 'expired' && $this->expires_at->gt(CarbonImmutable::now('UTC')))
                || ($this->outcome === 'superseded' && $this->sequence >= $head->issued_sequence)) {
                throw new LogicException('Refresh terminal outcome contradicts its current lease or sequence.');
            }
            if ($this->row_count < 0 || $this->row_count > 2000 || ($this->reliable && ! $this->observed)
                || ($this->source_fingerprint !== null && preg_match('/\A[a-f0-9]{64}\z/', $this->source_fingerprint) !== 1)) {
                throw new LogicException('Refresh terminal receipt evidence is invalid.');
            }

            if (($this->nfl_injury_snapshot_id !== null || $this->nfl_depth_snapshot_id !== null) && $this->outcome !== 'applied') {
                throw new LogicException('Only applied refresh receipts may link produced history.');
            }
            if ($this->nfl_injury_snapshot_id !== null && $this->nfl_depth_snapshot_id !== null) {
                throw new LogicException('Refresh receipt cannot mix historical components.');
            }
            foreach (['nfl_injury_snapshot_id' => PlayerInjurySnapshot::class,
                'nfl_depth_snapshot_id' => DepthChartSnapshot::class] as $field => $model) {
                if ($this->$field === null) {
                    continue;
                }
                $snapshot = $model::on($this->getConnectionName())->whereKey($this->$field)->lockForUpdate()->first();
                $component = $field === 'nfl_injury_snapshot_id' ? 'injuries' : 'depth_chart';
                if ($snapshot === null || $head->sport !== 'nfl' || $head->component !== $component
                    || (int) $snapshot->team_id !== (int) $head->team_id
                    || ($component === 'depth_chart' && (int) $snapshot->season !== (int) $head->season_key)) {
                    throw new LogicException('Refresh history link must match its actual scope.');
                }
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw new LogicException('Refresh attempt audit receipts cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Refresh attempt numeric mutations are refused.');
    }
}
