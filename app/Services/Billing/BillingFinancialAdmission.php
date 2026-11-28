<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Models\User;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Durable owner intent, before any network or queue dispatch; repeated equivalent intent reuses its cursor. */
final class BillingFinancialAdmission
{
    public function __construct(private readonly BillingMutationBoundary $database, private readonly BillingMutationJournal $journal,
        private readonly CanonicalPayloadHasher $hasher, private readonly BillingRoleEntitlements $roles) {}

    public function admit(User $subject, string $kind, ?string $price = null): BillingMutationIntent
    {
        $this->database->assert($subject);
        if (! config('billing.durable.enabled', false) || ! in_array($kind, ['cancel', 'resume', 'swap', 'checkout', 'portal'], true)) {
            throw ValidationException::withMessages(['billing' => ['Coordinated billing is not available. No provider operation was started.']]);
        }
        $context = new BillingAccountContext;
        if (in_array($kind, ['checkout', 'portal'], true)) {
            BillingReturnUrl::route($kind === 'portal' ? 'subscription.manage' : 'subscription.success');
        }
        if (in_array($kind, ['swap', 'checkout'], true) && (! is_string($price) || preg_match('/\Aprice_[A-Za-z0-9_]{1,185}\z/', $price) !== 1)) {
            throw ValidationException::withMessages(['billing' => ['The billing price is unavailable.']]);
        }

        return DB::transaction(function () use ($subject, $kind, $price, $context): BillingMutationIntent {
            $user = User::query()->lockForUpdate()->findOrFail($subject->id);
            if ($user->stripe_id !== null && (preg_match('/\Acus_[A-Za-z0-9_]{1,187}\z/', (string) $user->stripe_id) !== 1
                || app(BillingCustomerIdentity::class)->users($user->stripe_id, false)->count() !== 1)) {
                throw ValidationException::withMessages(['billing' => ['Billing customer ownership requires support review.']]);
            }
            if (in_array($kind, ['swap', 'checkout'], true)) {
                try {
                    $this->roles->assertCanonicalAdmission($user, true);
                } catch (BillingProviderFailure) {
                    throw ValidationException::withMessages(['billing' => ['Billing access provenance must be configured and classified before a new purchase. Contact support; no provider operation was started.']]);
                }
            }
            if (in_array($kind, ['swap', 'checkout'], true) && $this->roles->manualOverrideTier($user) !== null) {
                throw ValidationException::withMessages(['billing' => ['Your access has an administrator override. Contact support before purchasing or changing a paid plan; billing and access are managed separately.']]);
            }
            $rows = $user->subscriptions()->reorder('id')->lockForUpdate()->limit(9)->get();
            if ($rows->count() > 8) {
                throw ValidationException::withMessages(['billing' => ['Billing inventory requires support review.']]);
            }
            $subscription = $user->subscription();
            if (in_array($kind, ['cancel', 'resume', 'swap'], true)) {
                if ($subscription === null || $subscription->billing_projection_attempt_id === null || ! is_string($user->stripe_id)) {
                    throw ValidationException::withMessages(['billing' => ['Refresh billing through support before changing this subscription.']]);
                }
                if ($kind === 'swap' && $subscription->stripe_price === $price) {
                    throw ValidationException::withMessages(['billing' => ['This billing plan is already selected.']]);
                }
            }
            if ($kind === 'portal' && (! is_string($user->stripe_id) || ! is_string(config('billing.portal.configuration_id')))) {
                throw ValidationException::withMessages(['billing' => ['Secure payment management is not configured. Local cancellation remains available; contact support for payment changes.']]);
            }
            $hash = $this->hasher->hash(['kind' => $kind, 'user_id' => (int) $user->id, 'customer_id' => $user->stripe_id,
                'subscription_id' => in_array($kind, ['cancel', 'resume', 'swap'], true) ? $subscription->stripe_id : null, 'price' => $price]);
            $state = BillingMutationState::query()->where('subject_user_id', $user->id)->lockForUpdate()->first();
            $state ??= BillingMutationState::query()->create(['subject_user_id' => $user->id, 'user_id' => $user->id])->refresh();
            if ($state->active_intent_id !== null) {
                $existing = BillingMutationIntent::query()->findOrFail($state->active_intent_id);
                if ($existing->subject_user_id === (int) $user->id && hash_equals($existing->request_hash, $hash)) {
                    return $existing;
                }
                throw ValidationException::withMessages(['billing' => ['A prior billing operation must be resolved before another can start.']]);
            }
            $intent = BillingMutationIntent::query()->create(['operation_uuid' => (string) Str::uuid(), 'subject_user_id' => $user->id,
                'user_id' => $user->id, 'actor_user_id' => $user->id, 'kind' => $kind, 'policy' => 'cashier_single_licensed_v1',
                ...$context->scope(), 'requested_customer_id' => $user->stripe_id,
                'requested_subscription_id' => in_array($kind, ['cancel', 'resume', 'swap'], true) ? $subscription->stripe_id : null,
                'desired_price_id' => $price, 'request_hash' => $hash, 'facts' => ['purpose' => 'authenticated_billing_request'], 'requested_at' => BillingClock::now()]);
            $state->update(['active_intent_id' => $intent->id, 'customer_id' => $user->stripe_id, 'status' => 'pending',
                'owner_token' => null, 'lease_expires_at' => null, 'execution_started_at' => null, 'attempts' => 0,
                'next_attempt_at' => BillingClock::now(), 'failure_category' => null]);
            $this->journal->record($state, $intent, 'requested', ['requested_kind' => $kind, 'desired_price_id' => $price, 'policy' => $intent->policy]);

            return $intent;
        }, attempts: 3);
    }
}
