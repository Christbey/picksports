<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Models\BillingReconciliationAttempt;
use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use RuntimeException;

/** Reviewed local SQL projection only; never calls Stripe, a queue, or a payment method. */
class BillingProjectionWriter
{
    public function __construct(private readonly BillingDatabaseBoundary $database) {}

    public function apply(User $user, BillingCustomerReconciliation $state, BillingReconciliationAttempt $attempt, array $observation): array
    {
        $this->database->assert(false);
        if (DB::transactionLevel() === 0 || $user->stripe_id !== $state->customer_id) {
            throw new RuntimeException('Billing projection requires the locked exact customer.');
        }
        $this->validate($observation);

        return app(BillingCanonicalWriteContext::class)->run($state, $attempt, function () use ($user, $attempt, $observation): array {
            $mutation = BillingMutationState::query()->where('subject_user_id', $user->id)->lockForUpdate()->first();
            if ($mutation?->active_intent_id !== null) {
                $intent = BillingMutationIntent::query()->findOrFail($mutation->active_intent_id);
                if (str_starts_with($intent->kind, 'legacy_') || $intent->kind === 'checkout_guard') {
                    throw new BillingProviderFailure('legacy_financial_operation_unresolved');
                }
            }
            app(BillingFinancialProjection::class)->guard($user, $observation);
            $before = $this->capture($user, $attempt->targets, $observation['customer_deleted']);
            if ($observation['customer_deleted']) {
                $rows = $this->deletableSubscriptions($user)->orderBy('id')->limit(101)->lockForUpdate()->get();
                if ($rows->count() > 100) {
                    throw new BillingProviderFailure('projection_inventory_limit');
                }
                foreach ($rows as $subscription) {
                    $end = min($observation['fetch_completed_at'], now()->getTimestamp());
                    $subscription->forceFill(['stripe_status' => 'canceled', 'trial_ends_at' => null,
                        'ends_at' => BillingTime::mirror($end), 'billing_end_epoch' => $end, 'billing_trial_end_epoch' => null,
                        'billing_created_epoch' => $subscription->billing_created_epoch,
                        'billing_current_period_end_epoch' => null, 'billing_cancel_at_period_end' => false,
                        'billing_projection_attempt_id' => $attempt->id])->save();
                }
                if (! ($attempt->details['has_receipt_overflow'] ?? false)) {
                    $user->forceFill(['stripe_id' => null, 'trial_ends_at' => null, 'pm_type' => null, 'pm_last_four' => null])->save();
                }
            } else {
                foreach ($observation['subscriptions'] as $snapshot) {
                    $this->subscription($user, $snapshot, $observation['fetch_completed_at'], $attempt->id);
                }
            }
            $user->unsetRelation('subscriptions');
            $tier = $this->tier($user);
            app(BillingRoleEntitlements::class)->sync($user, $tier, $attempt);
            app(BillingFinancialProjection::class)->complete($user, $observation);
            $auditTargets = array_values(array_unique([...$attempt->targets, ...array_column($before['subscriptions'], 'stripe_id')]));
            $after = $this->capture($user, $auditTargets, false);
            $after['customer_deleted'] = $observation['customer_deleted'];
            $after['deletion_authority'] = $observation['customer_deleted'] ? $observation['deletion_proof'] : null;
            $after['identity_cleanup_deferred'] = $observation['customer_deleted'] && ($attempt->details['has_receipt_overflow'] ?? false);

            return ['before' => $before, 'after' => $after];
        }, $observation['customer_deleted']);
    }

    private function subscription(User $user, array $data, int $observed, int $attemptId): void
    {
        $class = Cashier::$subscriptionModel;
        $existing = $class::query()->where('stripe_id', $data['id'])->lockForUpdate()->limit(2)->get();
        if ($existing->count() > 1 || ($existing->isNotEmpty()
            && ($existing->first()->stripe_id !== $data['id'] || (int) $existing->first()->user_id !== (int) $user->id))) {
            throw new BillingProviderFailure('projection_identity_collision');
        }
        $subscription = $existing->first();
        if ($data['status'] === 'incomplete_expired') {
            if ($subscription) {
                $subscription->items()->delete();
                $subscription->delete();
            }

            return;
        }
        $subscription ??= new $class(['user_id' => $user->id, 'stripe_id' => $data['id'], 'created_at' => BillingTime::mirror($data['created'])]);
        $end = null;
        $trial = $data['trial_end'];
        if ($data['status'] === 'canceled') {
            $trial = null;
            // A missing provider terminal date uses the actual observation time as a LOCAL cancellation marker.
            $end = $data['ended_at'] ?? $data['canceled_at'] ?? $observed;
            if ($end > $observed) {
                throw new BillingProviderFailure('projection_terminal_date_invalid');
            }
            $end = min($end, now()->getTimestamp());
        } elseif ($data['cancel_at_period_end']) {
            if ($trial !== null && $trial > $observed) {
                $end = $trial;
            } else {
                $periods = array_column($data['items'], 'current_period_end');
                if ($periods === [] || in_array(null, $periods, true)) {
                    throw new BillingProviderFailure('projection_period_incomplete');
                }
                $end = max($periods);
            }
        } else {
            $end = $data['cancel_at'] ?? $data['canceled_at'];
        }
        $single = count($data['items']) === 1 ? $data['items'][0] : null;
        $periods = array_column($data['items'], 'current_period_end');
        $period = $periods !== [] && ! in_array(null, $periods, true) ? max($periods) : null;
        $subscription->forceFill(['type' => $subscription->type ?? $data['type'], 'stripe_status' => $data['status'],
            'stripe_price' => $single['price'] ?? null, 'quantity' => $single['quantity'] ?? null,
            'trial_ends_at' => BillingTime::mirror($trial), 'ends_at' => BillingTime::mirror($end),
            'billing_trial_end_epoch' => $trial, 'billing_end_epoch' => $end, 'billing_created_epoch' => $data['created'],
            'billing_current_period_end_epoch' => $period, 'billing_cancel_at_period_end' => $data['cancel_at_period_end'],
            'billing_projection_attempt_id' => $attemptId])->save();
        $ids = [];
        foreach ($data['items'] as $item) {
            $itemClass = $subscription->items()->getRelated()::class;
            $existingItems = $itemClass::query()->where('stripe_id', $item['id'])->lockForUpdate()->limit(2)->get();
            if ($existingItems->count() > 1 || ($existingItems->isNotEmpty()
                && ($existingItems->first()->stripe_id !== $item['id'] || (int) $existingItems->first()->subscription_id !== (int) $subscription->id))) {
                throw new BillingProviderFailure('projection_item_identity_collision');
            }
            $row = $existingItems->first() ?? new $itemClass(['subscription_id' => $subscription->id, 'stripe_id' => $item['id']]);
            $row->forceFill(['stripe_price' => $item['price'], 'stripe_product' => $item['product'], 'quantity' => $item['quantity']])->save();
            $ids[] = $row->id;
        }
        $subscription->items()->whereNotIn('id', $ids)->delete();
        if ($user->trial_ends_at !== null) {
            $user->forceFill(['trial_ends_at' => null])->save();
        }
    }

    private function tier(User $user): SubscriptionTier
    {
        $query = $user->subscriptions();
        $column = $query->getQuery()->getModel()->getConnection()->getDriverName() === 'mysql' ? 'CAST(type AS BINARY) = ?' : 'type COLLATE BINARY = ?';
        $rows = $query->whereRaw($column, ['default'])->reorder('id')->limit(101)->lockForUpdate()->get();
        if ($rows->count() > 100) {
            throw new BillingProviderFailure('projection_inventory_limit');
        }
        $subscription = null;
        if ($rows->isNotEmpty() && ! $rows->every(fn ($row) => ! $row->valid())) {
            if ($rows->contains(fn ($row) => ! $row->hasCanonicalProjection() || $row->billing_created_epoch === null)) {
                throw new BillingProviderFailure('projection_default_inventory_unobserved');
            }
            $latest = $rows->max('billing_created_epoch');
            $candidates = $rows->where('billing_created_epoch', $latest);
            if ($candidates->count() !== 1) {
                throw new BillingProviderFailure('projection_default_chronology_ambiguous');
            }
            $subscription = $candidates->first();
        }
        if ($subscription?->valid() && is_string($subscription->stripe_price)) {
            $mysql = $user->getConnection()->getDriverName() === 'mysql';
            $predicate = $mysql ? '(CAST(stripe_price_id_monthly AS BINARY) = ? OR CAST(stripe_price_id_yearly AS BINARY) = ?)'
                : '(stripe_price_id_monthly COLLATE BINARY = ? OR stripe_price_id_yearly COLLATE BINARY = ?)';
            $tiers = SubscriptionTier::query()->whereRaw($predicate, [$subscription->stripe_price, $subscription->stripe_price])->limit(2)->lockForUpdate()->get();
            if ($tiers->count() > 1) {
                throw new BillingProviderFailure('projection_ambiguous_price');
            }
            if ($tiers->isNotEmpty()) {
                $tier = $tiers->first();
                if (! in_array($subscription->stripe_price, [$tier->stripe_price_id_monthly, $tier->stripe_price_id_yearly], true)) {
                    throw new BillingProviderFailure('projection_price_identity_collision');
                }

                return $tier;
            }
        }

        return SubscriptionTier::query()->where('slug', (string) config('subscriptions.default_tier', 'free'))->lockForUpdate()->first()
            ?? throw new BillingProviderFailure('projection_default_tier_missing');
    }

    private function capture(User $user, array $targets, bool $deleted): array
    {
        $query = $deleted ? $this->deletableSubscriptions($user) : $user->subscriptions()->whereIn('stripe_id', $targets);
        $rows = $query->orderBy('id')->limit(101)->lockForUpdate()->get();
        if ($rows->count() > 100) {
            throw new BillingProviderFailure('projection_inventory_limit');
        }
        $attributes = static function (Model $row, array $fields): array {
            return array_intersect_key($row->getRawOriginal(), array_flip($fields));
        };

        return ['user_id' => $user->id, 'stripe_id' => $user->stripe_id,
            'subscriptions' => $rows->map(function ($row) use ($attributes): array {
                $items = $row->items()->orderBy('id')->limit(101)->lockForUpdate()->get();
                if ($items->count() > 100) {
                    throw new BillingProviderFailure('projection_inventory_limit');
                }

                return [...$attributes($row, ['id', 'stripe_id', 'type', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at', 'created_at',
                    'billing_projection_attempt_id', 'billing_trial_end_epoch', 'billing_end_epoch', 'billing_created_epoch', 'billing_current_period_end_epoch', 'billing_cancel_at_period_end']),
                    'items' => $items->map(fn ($item) => $attributes($item, ['id', 'stripe_id', 'stripe_price', 'stripe_product', 'quantity']))->all()];
            })->all(), 'roles' => $user->roles()->orderBy('name')->pluck('name')->all()];
    }

    private function deletableSubscriptions(User $user)
    {
        return $user->subscriptions()->where(function ($query): void {
            $query->where('stripe_status', '!=', 'canceled')->orWhere(fn ($branch) => $branch->notCanceled())
                ->orWhere(fn ($branch) => $branch->onGracePeriod())->orWhere(fn ($branch) => $branch->onTrial());
        });
    }

    private function validate(array $observation): void
    {
        if (! is_int($observation['fetch_completed_at'] ?? null) || ! is_int($observation['fetch_started_at'] ?? null)
            || $observation['fetch_started_at'] < 0 || $observation['fetch_completed_at'] > 253402300799
            || $observation['fetch_started_at'] > $observation['fetch_completed_at']
            || $observation['fetch_completed_at'] > now()->getTimestamp() + 300) {
            throw new BillingProviderFailure('projection_observation_invalid');
        }
        $seen = $seenItems = [];
        $items = 0;
        foreach ($observation['subscriptions'] as $row) {
            if (! is_array($row) || ($row['customer_id'] ?? null) !== $observation['customer_id'] || ($row['livemode'] ?? null) !== $observation['livemode']
                || ! $this->identifier($row['id'] ?? null, 'sub_') || isset($seen[$row['id']]) || ! is_string($row['type'] ?? null)
                || $row['type'] === '' || mb_strlen($row['type']) > 255 || preg_match('/[\x00-\x1f\x7f]/', $row['type'])
                || ! in_array($row['status'] ?? null, ['active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'paused'], true)
                || ! is_bool($row['cancel_at_period_end'] ?? null) || ! is_array($row['items'] ?? null) || ! array_is_list($row['items'])) {
                throw new BillingProviderFailure('projection_observation_invalid');
            }
            $seen[$row['id']] = true;
            if ($row['items'] === [] && ! in_array($row['status'], ['canceled', 'incomplete_expired'], true)) {
                throw new BillingProviderFailure('projection_inventory_incomplete');
            }
            foreach (['created', 'trial_end', 'cancel_at', 'canceled_at', 'ended_at'] as $field) {
                if (! array_key_exists($field, $row) || ($row[$field] !== null && (! is_int($row[$field]) || $row[$field] < 0 || $row[$field] > 253402300799))
                    || ($field === 'created' && $row[$field] === null)) {
                    throw new BillingProviderFailure('projection_observation_invalid');
                }
            }
            foreach ($row['items'] as $item) {
                if (++$items > 100 || ! is_array($item) || ! $this->identifier($item['id'] ?? null, 'si_') || isset($seenItems[$item['id']])
                    || ! $this->identifier($item['price'] ?? null) || ! $this->identifier($item['product'] ?? null)
                    || ! array_key_exists('quantity', $item) || ! array_key_exists('current_period_end', $item)
                    || ($item['quantity'] !== null && (! is_int($item['quantity']) || $item['quantity'] < 0 || $item['quantity'] > 2147483647))
                    || ($item['current_period_end'] !== null && (! is_int($item['current_period_end']) || $item['current_period_end'] < 0 || $item['current_period_end'] > 253402300799))) {
                    throw new BillingProviderFailure('projection_observation_invalid');
                }
                $seenItems[$item['id']] = true;
            }
        }
    }

    private function identifier(mixed $value, string $prefix = ''): bool
    {
        return is_string($value) && strlen($value) <= 191 && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_]+\z/', $value) === 1;
    }
}
