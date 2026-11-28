<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VerifyStripeWebhookSignature;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Billing\BillingJobHandoff;
use App\Services\Billing\BillingLegacyCheckoutCompletion;
use App\Services\Billing\BillingWebhookAdmission;
use App\Services\SubscriptionPeriodEndResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;
use Laravel\Cashier\Subscription;
use LogicException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class WebhookController extends CashierController
{
    private ?User $lockedBillingUser = null;

    public function __construct(private readonly SubscriptionPeriodEndResolver $periodEndResolver)
    {
        $this->middleware(VerifyStripeWebhookSignature::class);
    }

    public function handleCustomerSubscriptionUpdated(array $payload): void
    {
        $this->applySubscriptionEvent($payload, 'updated');
    }

    public function handleCustomerSubscriptionCreated(array $payload): void
    {
        $this->applySubscriptionEvent($payload, 'created');
    }

    public function handleCustomerSubscriptionDeleted(array $payload): void
    {
        $this->applySubscriptionEvent($payload, 'deleted');
    }

    private function applySubscriptionEvent(array $payload, string $operation): void
    {
        if (config('billing.durable.enabled', false)) {
            $receipt = app(BillingWebhookAdmission::class)->admit($payload, 'customer.subscription.'.$operation);
            app(BillingJobHandoff::class)->receipt($receipt);

            return;
        }
        if ((new User)->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('Billing webhook requires a standalone database transaction');
        }
        $customerId = $payload['data']['object']['customer'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            throw new LogicException('Invalid billing customer identity');
        }
        if (Cashier::$customerModel !== User::class) {
            throw new LogicException('Unsupported billing customer model');
        }

        $users = User::query()->where('stripe_id', $customerId)->limit(2)->get();
        if ($users->count() > 1) {
            throw new LogicException('Ambiguous billing customer identity');
        }
        $user = $users->first();
        if (! $user) {
            return; // Preserve Cashier's existing unknown-customer acknowledgement.
        }
        if ($user->stripe_id !== $customerId) {
            throw new LogicException('Billing customer identity does not match exactly.');
        }
        $this->assertSameConnections($user);
        $this->assertLegacyProjection($user);
        [$normalized, $expectedSnapshot] = $this->prepareLocalPayload($user, $payload, $operation);

        $user->getConnection()->transaction(function () use ($user, $customerId, $payload, $normalized, $expectedSnapshot, $operation): void {
            $users = User::query()->where('stripe_id', $customerId)->orderBy('id')->limit(2)->lockForUpdate()->get();
            if ($users->count() !== 1 || ! $users->first()->is($user) || $users->first()->stripe_id !== $customerId) {
                throw new LogicException('Ambiguous billing customer identity');
            }
            $locked = $users->first();
            $this->assertSameConnections($locked);
            $this->assertLegacyProjection($locked, true);
            if ($expectedSnapshot !== null && $expectedSnapshot !== $this->subscriptionSnapshot($locked, $payload, current: true)) {
                throw new RuntimeException('Billing subscription changed during period lookup; retry required');
            }
            $this->lockedBillingUser = $locked;
            try {
                match ($operation) {
                    'created' => parent::handleCustomerSubscriptionCreated($normalized),
                    'updated' => parent::handleCustomerSubscriptionUpdated($normalized),
                    'deleted' => parent::handleCustomerSubscriptionDeleted($normalized),
                };
                $this->syncUserRole($payload);
                app(BillingLegacyCheckoutCompletion::class)->linked($locked, $payload);
            } finally {
                $this->lockedBillingUser = null;
            }
        });
    }

    public function handleCustomerDeleted(array $payload)
    {
        if (config('billing.durable.enabled', false)) {
            $receipt = app(BillingWebhookAdmission::class)->admit($payload, 'customer.deleted');
            app(BillingJobHandoff::class)->receipt($receipt);

            return $this->successMethod();
        }

        $customer = $payload['data']['object']['id'] ?? null;
        if (! is_string($customer) || $customer === '' || (new User)->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('Customer deletion requires a standalone billing lifecycle.');
        }

        return (new User)->getConnection()->transaction(function () use ($payload, $customer) {
            $users = User::query()->where('stripe_id', $customer)->orderBy('id')->limit(2)->lockForUpdate()->get();
            if ($users->count() > 1 || ($users->isNotEmpty() && $users->first()->stripe_id !== $customer)) {
                throw new LogicException('Customer deletion identity is ambiguous.');
            }
            if ($users->isEmpty()) {
                return $this->successMethod();
            }
            $user = $users->first();
            $this->assertSameConnections($user);
            $this->assertLegacyProjection($user, true);
            $this->lockedBillingUser = $user;
            try {
                return parent::handleCustomerDeleted($payload);
            } finally {
                $this->lockedBillingUser = null;
            }
        });
    }

    private function assertLegacyProjection(User $user, bool $current = false): void
    {
        $query = $user->subscriptions()->whereNotNull('billing_projection_attempt_id');
        if ($current) {
            $query->lockForUpdate();
        }
        if ($query->first(['id']) !== null) {
            throw new LogicException('Canonical billing requires the durable event admission path even when issuance is disabled.');
        }
    }

    private function assertSameConnections(User $user): void
    {
        $subscription = $user->subscriptions()->getRelated();
        $models = [
            $subscription, $subscription->items()->getRelated(), new SubscriptionTier,
            new Role, new Permission,
            $user->roles()->getRelated(), $user->permissions()->getRelated(),
        ];
        foreach ($models as $model) {
            if ($model->getConnection()->getName() !== $user->getConnection()->getName()) {
                throw new LogicException('Billing models must use the same database connection');
            }
        }
    }

    private function prepareLocalPayload(User $user, array $payload, string $operation): array
    {
        $data = $payload['data']['object'];
        if ($operation !== 'updated' || ! ($data['cancel_at_period_end'] ?? false) || ($data['status'] ?? null) === 'incomplete_expired') {
            return [$payload, null];
        }
        $snapshot = $this->subscriptionSnapshot($user, $payload);
        $subscription = $user->subscriptions()->where('stripe_id', $data['id'])->first();
        $trial = $subscription ? clone $subscription : new Subscription;
        if (isset($data['trial_end'])) {
            $trial->trial_ends_at = Carbon::createFromTimestamp($data['trial_end']);
        }
        $end = $trial->onTrial()
            ? $trial->trial_ends_at
            : ($subscription ? $this->periodEndResolver->resolve($subscription) : null);

        // Only the internal vendor copy changes. Signature verification uses original bytes.
        $payload['data']['object']['cancel_at_period_end'] = false;
        unset($payload['data']['object']['cancel_at'], $payload['data']['object']['canceled_at']);
        if ($end !== null) {
            // Preserve Cashier's raw SQL wall-clock date shape, including cast trial dates.
            // Normalize trial copies too: expiry while locking must never trigger remote I/O.
            $payload['data']['object']['cancel_at'] = Carbon::createFromFormat(
                'Y-m-d H:i:s', $end->format('Y-m-d H:i:s'), 'UTC'
            )->getTimestamp();
        }

        return [$payload, $snapshot];
    }

    private function subscriptionSnapshot(User $user, array $payload, bool $current = false): string
    {
        $query = $user->subscriptions()->where('stripe_id', $payload['data']['object']['id']);
        $subscription = ($current ? $query->lockForUpdate() : $query)->first();
        $attributes = static function (Model $model): array {
            $values = $model->getRawOriginal();
            ksort($values);

            return $values;
        };

        $items = $subscription?->items()->orderBy('id');
        if ($current && $items !== null) {
            $items->lockForUpdate();
        }

        return hash('sha256', serialize($subscription ? [
            $attributes($subscription),
            $items->get()->map($attributes)->all(),
        ] : null));
    }

    protected function getUserByStripeId($stripeId)
    {
        if ($this->lockedBillingUser !== null) {
            if ($this->lockedBillingUser->stripe_id !== $stripeId) {
                throw new LogicException('Billing customer identity changed');
            }

            return $this->lockedBillingUser;
        }

        return parent::getUserByStripeId($stripeId);
    }

    protected function syncUserRole(array $payload): void
    {
        $this->lockedBillingUser?->syncRoleFromTier();
    }
}
