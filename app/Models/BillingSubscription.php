<?php

namespace App\Models;

use App\Services\Billing\BillingCanonicalWriteContext;
use App\Services\Billing\BillingClock;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use LogicException;
use Stripe\Subscription as StripeSubscription;

/** Exact instants only for explicitly observed rows; untouched Cashier rows keep their original semantics. */
class BillingSubscription extends Subscription
{
    protected $table = 'subscriptions';

    protected $casts = [
        'ends_at' => 'datetime', 'trial_ends_at' => 'datetime', 'quantity' => 'integer',
        'billing_trial_end_epoch' => 'integer', 'billing_end_epoch' => 'integer',
        'billing_current_period_end_epoch' => 'integer', 'billing_created_epoch' => 'integer',
        'billing_cancel_at_period_end' => 'boolean', 'billing_projection_attempt_id' => 'integer',
    ];

    public const EPOCH_FIELDS = ['billing_trial_end_epoch', 'billing_end_epoch', 'billing_current_period_end_epoch',
        'billing_created_epoch', 'billing_cancel_at_period_end', 'billing_projection_attempt_id'];

    private const BUSINESS_FIELDS = ['user_id', 'stripe_id', 'type', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at', 'created_at'];

    private bool $businessAssigned = false;

    public function getForeignKey()
    {
        return 'subscription_id';
    }

    public function hasCanonicalProjection(): bool
    {
        return ($this->attributes['billing_projection_attempt_id'] ?? null) !== null;
    }

    protected function endsAt(): Attribute
    {
        return Attribute::get(fn ($value) => $this->dateValue('billing_end_epoch', $value));
    }

    protected function trialEndsAt(): Attribute
    {
        return Attribute::get(fn ($value) => $this->dateValue('billing_trial_end_epoch', $value));
    }

    protected function createdAt(): Attribute
    {
        return Attribute::get(fn ($value) => $this->hasCanonicalProjection() && $this->billing_created_epoch !== null
            ? Carbon::createFromTimestampUTC($this->billing_created_epoch)->setTimezone(config('app.timezone', 'UTC'))
            : ($value === null ? null : $this->asDateTime($value)));
    }

    private function dateValue(string $epoch, mixed $legacy): ?CarbonInterface
    {
        if ($this->hasCanonicalProjection()) {
            $value = $this->getAttribute($epoch);

            return $value === null ? null : Carbon::createFromTimestampUTC($value)->setTimezone(config('app.timezone', 'UTC'));
        }

        return $legacy === null ? null : $this->asDateTime($legacy);
    }

    public function onGracePeriod(): bool
    {
        return $this->hasCanonicalProjection()
            ? $this->billing_end_epoch !== null && $this->billing_end_epoch > BillingClock::now()->getTimestamp()
            : parent::onGracePeriod();
    }

    public function onTrial(): bool
    {
        return $this->hasCanonicalProjection()
            ? $this->billing_trial_end_epoch !== null && $this->billing_trial_end_epoch > BillingClock::now()->getTimestamp()
            : parent::onTrial();
    }

    public function hasExpiredTrial(): bool
    {
        return $this->hasCanonicalProjection()
            ? $this->billing_trial_end_epoch !== null && $this->billing_trial_end_epoch <= BillingClock::now()->getTimestamp()
            : parent::hasExpiredTrial();
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, self::BUSINESS_FIELDS, true)) {
            $this->businessAssigned = true;
        }

        return parent::setAttribute($key, $value);
    }

    public function save(array $options = []): bool
    {
        $business = $this->businessAssigned || $this->isDirty(self::BUSINESS_FIELDS);
        $epoch = $this->isDirty(self::EPOCH_FIELDS);
        $allowed = app(BillingCanonicalWriteContext::class)->allows((int) $this->user_id,
            $this->billing_projection_attempt_id === null ? null : (int) $this->billing_projection_attempt_id, $this->stripe_id)
            && $this->getConnection()->getName() === DB::connection()->getName();
        if ($allowed) {
            if (! $this->hasCanonicalProjection() || ($this->billing_created_epoch === null && $this->stripe_status !== 'canceled') || $this->billing_cancel_at_period_end === null) {
                throw new LogicException('Canonical subscription evidence is incomplete.');
            }

            return $this->getConnection()->transaction(function () use ($options): bool {
                if ($this->exists) {
                    $current = $this->newQuery()->without('items')->whereKey($this->id)->lockForUpdate()->firstOrFail();
                    if ((int) $current->user_id !== (int) $this->user_id || $current->stripe_id !== $this->stripe_id) {
                        throw new LogicException('Canonical subscription ownership is immutable.');
                    }
                }
                $saved = parent::save($options);
                $this->businessAssigned = false;

                return $saved;
            });
        }
        if ($epoch || (($business || ! $this->exists) && config('billing.durable.enabled', false))) {
            throw new LogicException('Subscription changes require coordinated billing admission.');
        }
        if ($this->exists && $business) {
            return $this->getConnection()->transaction(function () use ($options): bool {
                $current = $this->newQuery()->without('items')->whereKey($this->id)->lockForUpdate()->firstOrFail();
                if ($current->hasCanonicalProjection()) {
                    throw new LogicException('Canonical subscriptions require coordinated billing admission even when new admission is disabled.');
                }
                $saved = parent::save($options);
                $this->businessAssigned = false;

                return $saved;
            });
        }
        $saved = parent::save($options);
        $this->businessAssigned = false;

        return $saved;
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            $current = $this->newQuery()->without('items')->whereKey($this->id)->lockForUpdate()->first();
            if ($current === null) {
                return false;
            }
            if (config('billing.durable.enabled', false) || $current->hasCanonicalProjection()) {
                if (! app(BillingCanonicalWriteContext::class)->allows((int) $current->user_id, null, $current->stripe_id)
                    || $this->getConnection()->getName() !== DB::connection()->getName()) {
                    throw new LogicException('Subscription removal requires coordinated billing admission.');
                }
            }

            return parent::delete();
        });
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        $fields = [$column, ...array_keys($extra)];
        if (array_intersect($fields, self::EPOCH_FIELDS) !== []) {
            throw new LogicException('Use a coordinated explicit subscription projection instead of numeric mutation.');
        }
        if (array_intersect($fields, self::BUSINESS_FIELDS) === []) {
            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        }
        if (config('billing.durable.enabled', false)) {
            throw new LogicException('Subscription counters require coordinated billing admission.');
        }

        return $this->getConnection()->transaction(function () use ($column, $amount, $extra, $method) {
            $current = $this->newQuery()->without('items')->whereKey($this->id)->lockForUpdate()->firstOrFail();
            if ($current->hasCanonicalProjection()) {
                throw new LogicException('Canonical subscription counters are protected.');
            }

            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        });
    }

    public function scopeActive(Builder $query): void
    {
        $this->compareDate($query, 'ends_at', 'billing_end_epoch', '>', true);
        $query->where('stripe_status', '!=', StripeSubscription::STATUS_INCOMPLETE_EXPIRED)->where('stripe_status', '!=', StripeSubscription::STATUS_UNPAID);
        if (Cashier::$deactivatePastDue) {
            $query->where('stripe_status', '!=', StripeSubscription::STATUS_PAST_DUE);
        }
        if (Cashier::$deactivateIncomplete) {
            $query->where('stripe_status', '!=', StripeSubscription::STATUS_INCOMPLETE);
        }
    }

    public function scopeCanceled(Builder $query): void
    {
        $this->nullDate($query, 'ends_at', 'billing_end_epoch', false);
    }

    public function scopeNotCanceled(Builder $query): void
    {
        $this->nullDate($query, 'ends_at', 'billing_end_epoch', true);
    }

    public function scopeOnTrial(Builder $query): void
    {
        $this->compareDate($query, 'trial_ends_at', 'billing_trial_end_epoch', '>');
    }

    public function scopeExpiredTrial(Builder $query): void
    {
        $this->compareDate($query, 'trial_ends_at', 'billing_trial_end_epoch', '<', false, '<=');
    }

    public function scopeNotOnTrial(Builder $query): void
    {
        $this->compareDate($query, 'trial_ends_at', 'billing_trial_end_epoch', '<=', true);
    }

    public function scopeOnGracePeriod(Builder $query): void
    {
        $this->compareDate($query, 'ends_at', 'billing_end_epoch', '>');
    }

    public function scopeNotOnGracePeriod(Builder $query): void
    {
        $this->compareDate($query, 'ends_at', 'billing_end_epoch', '<=', true);
    }

    private function compareDate(Builder $query, string $legacy, string $epoch, string $operator, bool $nullAllowed = false, ?string $canonicalOperator = null): void
    {
        $canonicalOperator ??= $operator;
        $query->where(function ($branches) use ($legacy, $epoch, $operator, $nullAllowed, $canonicalOperator): void {
            $branches->where(function ($canonical) use ($epoch, $canonicalOperator, $nullAllowed): void {
                $canonical->whereNotNull('billing_projection_attempt_id')->where(function ($value) use ($epoch, $canonicalOperator, $nullAllowed): void {
                    $value->where($epoch, $canonicalOperator, BillingClock::now()->getTimestamp());
                    if ($nullAllowed) {
                        $value->orWhereNull($epoch);
                    }
                });
            })->orWhere(function ($historical) use ($legacy, $operator, $nullAllowed): void {
                $historical->whereNull('billing_projection_attempt_id')->where(function ($value) use ($legacy, $operator, $nullAllowed): void {
                    $value->where($legacy, $operator, now());
                    if ($nullAllowed) {
                        $value->orWhereNull($legacy);
                    }
                });
            });
        });
    }

    private function nullDate(Builder $query, string $legacy, string $epoch, bool $null): void
    {
        $query->where(function ($branches) use ($legacy, $epoch, $null): void {
            $branches->where(function ($canonical) use ($epoch, $null): void {
                $canonical->whereNotNull('billing_projection_attempt_id');
                $null ? $canonical->whereNull($epoch) : $canonical->whereNotNull($epoch);
            })->orWhere(function ($historical) use ($legacy, $null): void {
                $historical->whereNull('billing_projection_attempt_id');
                $null ? $historical->whereNull($legacy) : $historical->whereNotNull($legacy);
            });
        });
    }
}
