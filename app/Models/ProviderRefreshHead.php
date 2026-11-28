<?php

namespace App\Models;

use App\Casts\UtcEvidenceDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProviderRefreshHead extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['issued_sequence' => 'integer', 'latest_terminal_sequence' => 'integer', 'latest_applied_sequence' => 'integer',
            'activated_at' => UtcEvidenceDateTime::class, 'drained_at' => UtcEvidenceDateTime::class,
            'created_at' => UtcEvidenceDateTime::class, 'updated_at' => UtcEvidenceDateTime::class];
    }

    /** Recorded operator statements, not independent proof workers drained or source formats were reviewed. */
    public function hasRecordedAttestations(): bool
    {
        foreach (['activation_operator' => 80, 'activation_reason' => 256, 'drain_reference' => 160, 'source_review_reference' => 160] as $field => $maximum) {
            $value = $this->$field;
            if (! is_string($value) || $value === '' || $value !== trim($value) || strlen($value) > $maximum
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                return false;
            }
        }

        return true;
    }

    public function freshTimestamp()
    {
        return CarbonImmutable::now('UTC');
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            if (! $this->hasRecordedAttestations() || $this->provider !== 'espn' || ! in_array($this->sport, ['nfl', 'nba', 'cfb', 'cbb', 'mlb', 'wnba', 'wcbb'], true)
                || ! in_array($this->component, ['injuries', 'depth_chart'], true) || (int) $this->team_id < 1
                || (int) $this->protocol_version !== 1 || preg_match('/\A[a-f0-9]{64}\z/', (string) $this->target_fingerprint) !== 1
                || $this->activated_at === null || $this->drained_at === null || $this->activated_at->isFuture()
                || $this->drained_at->gt($this->activated_at)
                || (int) $this->issued_sequence !== 0 || (int) $this->latest_terminal_sequence !== 0 || (int) $this->latest_applied_sequence !== 0
                || $this->latest_terminal_attempt_id !== null || $this->latest_applied_attempt_id !== null) {
                throw new LogicException('Refresh head requires an explicit valid empty reviewed activation.');
            }

            return parent::save($options);
        }
        if ($this->isDirty($this->getKeyName())) {
            throw new LogicException('Refresh head identity is immutable.');
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $persisted = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            foreach (['provider', 'sport', 'component', 'team_id', 'season_key', 'protocol_version', 'target_fingerprint', 'activated_at', 'drained_at', 'created_at', 'activation_operator', 'activation_reason', 'drain_reference', 'source_review_reference'] as $key) {
                if (($this->getAttributes()[$key] ?? null) !== ($persisted->getAttributes()[$key] ?? null)) {
                    throw new LogicException('Activated refresh scope and drain/target evidence are immutable.');
                }
            }
            foreach (['issued_sequence', 'latest_terminal_sequence', 'latest_applied_sequence'] as $key) {
                if (! is_int($this->$key) || $this->$key < $persisted->$key) {
                    throw new LogicException('Refresh head sequences cannot regress.');
                }
            }
            if ($this->issued_sequence > $persisted->issued_sequence) {
                if ($this->issued_sequence !== $persisted->issued_sequence + 1
                    || ! ProviderRefreshAttempt::on($this->getConnectionName())->where('head_id', $this->id)
                        ->where('sequence', $this->issued_sequence)->whereNull('outcome')->lockForUpdate()->first()) {
                    throw new LogicException('Refresh sequence admission requires its next running receipt.');
                }
            }
            if ($this->latest_applied_sequence > $this->latest_terminal_sequence || $this->latest_terminal_sequence > $this->issued_sequence) {
                throw new LogicException('Incoherent refresh head sequence.');
            }
            foreach (['terminal', 'applied'] as $kind) {
                $id = $this->{'latest_'.$kind.'_attempt_id'};
                $sequence = $this->{'latest_'.$kind.'_sequence'};
                if ($id === null && $sequence === 0) {
                    continue;
                }
                $attempt = ProviderRefreshAttempt::on($this->getConnectionName())->whereKey($id)->lockForUpdate()->first();
                if ($attempt === null || (int) $attempt->head_id !== (int) $this->id || (int) $attempt->sequence !== $sequence
                    || $attempt->outcome === null || ($kind === 'applied' && $attempt->outcome !== 'applied')) {
                    throw new LogicException('Refresh head must reference its matching terminal receipt.');
                }
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw new LogicException('Refresh ordering heads cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Refresh counters require guarded admission.');
    }
}
