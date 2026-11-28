<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** One admitted provider step per execution; possible-send is never automatically repeated. */
final class BillingFinancialExecutor
{
    public function __construct(private readonly BillingMutationBoundary $database, private readonly BillingMutationJournal $journal,
        private readonly BillingFinancialGateway $gateway, private readonly BillingOperatorAdmission $refresh) {}

    public function run(int $stateId, ?int $expectedIntent = null, ?string $dispatchToken = null): bool
    {
        if (! config('billing.durable.enabled', false)) {
            return false;
        }
        $this->database->assert();
        $lease = $this->claim($stateId, $expectedIntent, $dispatchToken);
        if ($lease === null) {
            return false;
        }
        $possible = false;
        try {
            [$intent, $user, $customer] = $this->readOwner($lease);
            $checkout = $intent->kind === 'checkout' ? BillingMutationReceipt::query()->where('intent_id', $intent->id)->where('phase', 'acknowledged_checkout')->first() : null;
            if ($checkout !== null) {
                $read = $this->gateway->execute(['operation' => 'checkout_read', 'account_id' => $intent->account_id, 'livemode' => $intent->livemode,
                    'operation_uuid' => $intent->operation_uuid, 'customer_id' => $customer, 'checkout_session_id' => $checkout->facts['checkout_session_id']]);
                $needsRefresh = $this->observeCheckout($lease, $intent, $checkout->facts['checkout_session_id'], $read);
                if ($needsRefresh) {
                    app(BillingObservedCheckoutAdmission::class)->admit($lease->stateId);
                }

                return true;
            }
            $kind = $intent->kind === 'checkout' && $customer === null ? 'customer' : $intent->kind;
            $request = ['operation' => 'prepare', 'kind' => $kind, 'operation_uuid' => $intent->operation_uuid,
                'account_id' => $intent->account_id, 'livemode' => $intent->livemode, 'customer_id' => $customer,
                'subscription_id' => $intent->requested_subscription_id, 'price_id' => $intent->desired_price_id];
            if ($kind === 'customer') {
                $request['email'] = $user->email;
                $request['name'] = (string) $user->stripeName();
            }
            $prepared = $this->gateway->execute($request);
            if (! is_array($prepared['prepared'] ?? null) || ! is_string($prepared['request_hash'] ?? null)
                || ! hash_equals($prepared['request_hash'], hash('sha256', json_encode($prepared['prepared'], JSON_THROW_ON_ERROR, 16)))
                || ($prepared['prepared']['kind'] ?? null) !== $kind) {
                throw new BillingProviderFailure('provider_protocol_failure');
            }
            $step = hash('sha256', $intent->operation_uuid.':'.$kind);
            $this->possible($lease, $intent, $kind, $step, $prepared['request_hash']);
            $possible = true;
            $ack = $this->gateway->execute(['operation' => 'send', 'account_id' => $intent->account_id,
                'livemode' => $intent->livemode, 'prepared' => $prepared['prepared'], 'request_hash' => $prepared['request_hash'], 'step_key' => $step]);
            $this->acknowledge($lease, $intent, $kind, $ack);
            if (in_array($kind, ['cancel', 'resume', 'swap'], true)) {
                // No provider read here: a separate durable customer reconciliation owns projection.
                $this->requestRefresh($intent);
            }

            return true;
        } catch (Throwable $error) {
            $this->failed($lease, $possible, $error instanceof BillingProviderFailure ? $error->category : 'financial_execution_failed');

            return false;
        }
    }

    private function claim(int $id, ?int $expectedIntent, ?string $dispatchToken): ?BillingMutationLease
    {
        $candidate = BillingMutationState::query()->find($id);
        if ($candidate === null) {
            return null;
        }

        return DB::transaction(function () use ($id, $candidate, $expectedIntent, $dispatchToken): ?BillingMutationLease {
            $user = User::query()->whereKey($candidate->subject_user_id)->lockForUpdate()->first();
            $state = BillingMutationState::query()->lockForUpdate()->find($id);
            if ($user === null || $state === null || $state->subject_user_id !== (int) $user->id || $state->active_intent_id === null) {
                return null;
            }
            $intent = BillingMutationIntent::query()->findOrFail($state->active_intent_id);
            if ($expectedIntent !== null && ($state->active_intent_id !== $expectedIntent || $state->status !== 'enqueued'
                || $dispatchToken === null || $state->owner_token === null || ! hash_equals($state->owner_token, $dispatchToken)
                || $state->lease_expires_at === null || $state->lease_expires_at->getTimestamp() <= BillingClock::now()->getTimestamp())) {
                return null;
            }
            if ($intent->subject_user_id !== (int) $user->id) {
                throw new LogicException('Financial subject changed.');
            }
            if (in_array($state->status, ['preparing', 'possible_send'], true)) {
                if ($state->lease_expires_at === null || $state->lease_expires_at->getTimestamp() > BillingClock::now()->getTimestamp()) {
                    return null;
                }
                if ($state->status === 'possible_send') {
                    $this->journal->record($state, $intent, 'expired_possible_send', ['category' => 'financial_outcome_unknown']);
                    $state->update(['status' => 'uncertain', 'failure_category' => 'financial_outcome_unknown', 'owner_token' => null]);

                    return null;
                }
            } elseif (! in_array($state->status, ['pending', 'pending_next', 'enqueued', 'session_open'], true)) {
                return null;
            }
            if ($state->next_attempt_at !== null && $state->next_attempt_at->getTimestamp() > BillingClock::now()->getTimestamp()) {
                return null;
            }
            if ($state->attempts >= 8) {
                $state->update(['status' => 'attention', 'failure_category' => 'financial_prepare_exhausted']);

                return null;
            }
            $context = new BillingAccountContext;
            if ($intent->account_id !== $context->accountId || $intent->livemode !== $context->livemode || $state->customer_id !== $user->stripe_id) {
                $state->update(['status' => 'attention', 'failure_category' => 'financial_context_changed']);

                return null;
            }
            $owner = (string) Str::uuid();
            $state->update(['status' => 'preparing', 'owner_token' => $owner, 'lease_sequence' => $state->lease_sequence + 1,
                'attempts' => $state->attempts + 1, 'execution_started_at' => BillingClock::now(), 'lease_expires_at' => BillingClock::now()->addSeconds(90)]);
            $this->journal->record($state, $intent, 'execution_started', ['requested_kind' => $intent->kind]);

            return new BillingMutationLease($state->id, $intent->id, $user->id, $owner, true);
        }, attempts: 3);
    }

    private function readOwner(BillingMutationLease $lease): array
    {
        return DB::transaction(function () use ($lease): array {
            [$user, $state, $intent] = $this->owned($lease);

            return [$intent, $user, $state->customer_id];
        });
    }

    private function possible(BillingMutationLease $lease, BillingMutationIntent $intent, string $kind, string $step, string $hash): void
    {
        DB::transaction(function () use ($lease, $intent, $kind, $step, $hash): void {
            [$user, $state] = $this->owned($lease);
            if ($state->status !== 'preparing' || $state->customer_id !== $user->stripe_id) {
                throw new LogicException('Financial send admission changed.');
            }
            if (in_array($intent->kind, ['checkout', 'swap'], true)) {
                app(BillingRoleEntitlements::class)->assertCanonicalAdmission($user, true);
            }
            if (in_array($intent->kind, ['checkout', 'swap'], true) && app(BillingRoleEntitlements::class)->manualOverrideTier($user) !== null) {
                throw new BillingProviderFailure('financial_manual_override');
            }
            $this->journal->record($state, $intent, 'possible_send_'.$kind, ['requested_kind' => $kind, 'prepared_request_hash' => $hash], $step, $hash);
            $state->update(['status' => 'possible_send']);
        }, attempts: 3);
    }

    private function acknowledge(BillingMutationLease $lease, BillingMutationIntent $intent, string $kind, array $ack): void
    {
        DB::transaction(function () use ($lease, $intent, $kind, $ack): void {
            [$user, $state] = $this->owned($lease);
            if ($state->status !== 'possible_send') {
                throw new LogicException('Financial acknowledgement ownership changed.');
            }
            $prefix = match ($kind) {
                'customer' => 'cus_', 'checkout' => 'cs_', 'portal' => 'bps_', default => 'sub_'
            };
            if (! is_string($ack['id'] ?? null) || ! str_starts_with($ack['id'], $prefix) || strlen($ack['id']) > 191 || preg_match('/\A[A-Za-z0-9_]+\z/', $ack['id']) !== 1) {
                throw new BillingProviderFailure('provider_protocol_failure');
            }
            $facts = ['requested_kind' => $kind, 'customer_id' => $ack['customer_id'] ?? null];
            if ($kind === 'customer') {
                if ($user->stripe_id !== null || ($ack['customer_id'] ?? null) !== $ack['id']) {
                    throw new BillingProviderFailure('financial_customer_link_changed');
                }
                $user->stripe_id = $ack['id'];
                $user->save();
                $facts['customer_id'] = $ack['id'];
                $this->journal->record($state, $intent, 'acknowledged_customer', $facts);
                $state->update(['customer_id' => $ack['id'], 'status' => 'pending_next', 'owner_token' => null,
                    'lease_expires_at' => null, 'next_attempt_at' => BillingClock::now(), 'attempts' => 0]);

                return;
            }
            if (($ack['customer_id'] ?? null) !== $state->customer_id || $user->stripe_id !== $state->customer_id) {
                throw new BillingProviderFailure('financial_customer_link_changed');
            }
            if (in_array($kind, ['checkout', 'portal'], true)) {
                $host = $kind === 'checkout' ? 'checkout.stripe.com' : 'billing.stripe.com';
                $this->assertUrl($ack['url'] ?? null, $host);
                $facts[$kind === 'checkout' ? 'checkout_session_id' : 'provider_request_id'] = $ack['id'];
                $this->journal->record($state, $intent, 'acknowledged_'.$kind, $facts);
                $state->update(['status' => $kind === 'checkout' ? 'session_open' : 'completed',
                    'active_intent_id' => $kind === 'portal' ? null : $intent->id, 'owner_token' => null, 'lease_expires_at' => null,
                    'result' => ['intent_id' => $intent->id, 'kind' => $kind, 'url' => $ack['url'], 'provider_id' => $ack['id']],
                    'result_expires_at' => BillingClock::now()->addMinutes(15)]);

                return;
            }
            if ($ack['id'] !== $intent->requested_subscription_id) {
                throw new BillingProviderFailure('financial_subscription_changed');
            }
            $facts['subscription_id'] = $ack['id'];
            $facts['remote_state'] = is_string($ack['status'] ?? null) ? $ack['status'] : null;
            if (isset($ack['invoice_id'])) {
                $facts['invoice_id'] = $ack['invoice_id'];
            }
            $facts['requires_action'] = $kind === 'swap' && ($ack['invoice_status'] ?? null) !== 'paid';
            if ($facts['requires_action'] && isset($ack['invoice_url'])) {
                $this->assertUrl($ack['invoice_url'], 'invoice.stripe.com');
            }
            $this->journal->record($state, $intent, 'acknowledged_'.$kind, $facts);
            $state->update(['status' => $facts['requires_action'] ? 'payment_pending' : 'awaiting_reconciliation',
                'owner_token' => null, 'lease_expires_at' => null, 'failure_category' => null,
                'result' => ['intent_id' => $intent->id, 'kind' => $kind, 'subscription_id' => $ack['id'], 'invoice_id' => $ack['invoice_id'] ?? null,
                    'url' => $facts['requires_action'] ? ($ack['invoice_url'] ?? null) : null],
                'result_expires_at' => BillingClock::now()->addMinutes(15)]);
        }, attempts: 3);
    }

    private function observeCheckout(BillingMutationLease $lease, BillingMutationIntent $intent, string $sessionId, array $read): bool
    {
        return DB::transaction(function () use ($lease, $intent, $sessionId, $read): bool {
            [$user, $state] = $this->owned($lease);
            if ($state->status !== 'preparing' || ($read['id'] ?? null) !== $sessionId || ($read['customer_id'] ?? null) !== $state->customer_id
                || $user->stripe_id !== $state->customer_id || ! in_array($read['status'] ?? null, ['open', 'complete', 'expired'], true)
                || ! in_array($read['payment_status'] ?? null, ['paid', 'unpaid', 'no_payment_required'], true)) {
                throw new BillingProviderFailure('provider_checkout_unbound');
            }
            if ($read['status'] === 'expired') {
                if (($read['subscription_id'] ?? null) !== null || $read['payment_status'] === 'paid') {
                    throw new BillingProviderFailure('provider_checkout_unbound');
                }
                $this->journal->record($state, $intent, 'checkout_expired', ['customer_id' => $state->customer_id, 'checkout_session_id' => $sessionId, 'remote_state' => 'expired']);
                $state->update(['status' => 'expired', 'active_intent_id' => null, 'owner_token' => null, 'lease_expires_at' => null,
                    'result' => ['intent_id' => $intent->id, 'kind' => 'checkout'], 'result_expires_at' => BillingClock::now()->addMinutes(15)]);

                return false;
            }
            if ($read['status'] === 'complete' && in_array($read['payment_status'], ['paid', 'no_payment_required'], true)) {
                $sub = $read['subscription_id'] ?? null;
                if (! is_string($sub) || preg_match('/\Asub_[A-Za-z0-9_]{1,187}\z/', $sub) !== 1) {
                    throw new BillingProviderFailure('provider_checkout_unbound');
                }
                $this->journal->record($state, $intent, 'checkout_observed', ['customer_id' => $state->customer_id, 'checkout_session_id' => $sessionId,
                    'subscription_id' => $sub, 'remote_state' => $read['payment_status']]);
                $state->update(['status' => 'awaiting_checkout_refresh', 'owner_token' => null, 'lease_expires_at' => null,
                    'next_attempt_at' => BillingClock::now(), 'result' => ['intent_id' => $intent->id, 'kind' => 'checkout', 'subscription_id' => $sub],
                    'result_expires_at' => BillingClock::now()->addMinutes(15)]);

                return true;
            }
            if (($read['url'] ?? null) !== null) {
                $this->assertUrl($read['url'], 'checkout.stripe.com');
            }
            $this->journal->record($state, $intent, 'checkout_checked', ['customer_id' => $state->customer_id, 'checkout_session_id' => $sessionId, 'remote_state' => $read['status']]);
            $state->update(['status' => 'session_open', 'owner_token' => null, 'lease_expires_at' => null, 'attempts' => 0, 'next_attempt_at' => BillingClock::now()->addMinute(),
                'result' => ['intent_id' => $intent->id, 'kind' => 'checkout', 'provider_id' => $sessionId, 'url' => $read['url'] ?? null],
                'result_expires_at' => BillingClock::now()->addMinutes(15)]);

            return false;
        }, attempts: 3);
    }

    private function requestRefresh(BillingMutationIntent $intent): void
    {
        $plans = $this->refresh->plan([], [(int) $intent->subject_user_id], []);
        $this->refresh->admit($plans[0], 'Financial operation acknowledged; refresh current provider projection');
    }

    private function failed(BillingMutationLease $lease, bool $possible, string $category): void
    {
        if (preg_match('/\A[a-z_]{1,100}\z/', $category) !== 1) {
            $category = 'financial_execution_failed';
        }
        try {
            DB::transaction(function () use ($lease, $possible, $category): void {
                [$user, $state, $intent] = $this->owned($lease, false);
                if (! in_array($state->status, ['preparing', 'possible_send'], true)) {
                    return;
                }
                $readOnlyCheckout = ! $possible && $state->status === 'preparing' && $intent->kind === 'checkout'
                    && BillingMutationReceipt::query()->where('intent_id', $intent->id)->where('phase', 'acknowledged_checkout')->exists();
                if ($readOnlyCheckout && $state->attempts < 8) {
                    $this->journal->record($state, $intent, 'checkout_read_failed', ['category' => $category]);
                    $state->update(['status' => 'session_open', 'failure_category' => $category, 'owner_token' => null, 'lease_expires_at' => null,
                        'next_attempt_at' => BillingClock::now()->addSeconds(min(3600, 60 * (2 ** min(6, $state->attempts))))]);

                    return;
                }
                $this->journal->record($state, $intent, 'execution_failed', ['category' => $category]);
                $state->update(['status' => $possible || $state->status === 'possible_send' ? 'uncertain' : 'attention',
                    'failure_category' => $category, 'owner_token' => null, 'lease_expires_at' => null]);
            });
        } catch (Throwable) { /* Lost owner is fenced; the scanner retains or marks expired possible-send work. */
        }
    }

    private function owned(BillingMutationLease $lease, bool $requireLive = true): array
    {
        $user = User::query()->lockForUpdate()->findOrFail($lease->userId);
        $state = BillingMutationState::query()->lockForUpdate()->findOrFail($lease->stateId);
        $intent = BillingMutationIntent::query()->findOrFail($lease->intentId);
        if ($state->subject_user_id !== $lease->userId || $intent->subject_user_id !== $lease->userId
            || $state->active_intent_id !== $lease->intentId || $state->owner_token === null || ! hash_equals($state->owner_token, $lease->ownerToken)
            || ($requireLive && ($state->lease_expires_at === null || $state->lease_expires_at->getTimestamp() <= BillingClock::now()->getTimestamp()))) {
            throw new LogicException('Financial owner is stale.');
        }

        return [$user, $state, $intent];
    }

    private function assertUrl(mixed $url, string $host): void
    {
        $p = is_string($url) ? parse_url($url) : false;
        if (! is_string($url) || strlen($url) > 4096 || ! is_array($p) || ($p['scheme'] ?? null) !== 'https' || ($p['host'] ?? null) !== $host
            || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new BillingProviderFailure('provider_url_invalid');
        }
    }
}
