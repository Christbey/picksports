<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Models\User;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Guards SDK-hidden customer/session creation; a timeout never releases the deletion hold. */
final class BillingCheckoutReservations
{
    public function __construct(private readonly BillingMutationBoundary $database, private readonly BillingMutationJournal $journal,
        private readonly CanonicalPayloadHasher $hasher) {}

    public function reserve(User $subject, string $price, array $options): BillingMutationLease
    {
        $this->database->assert($subject);
        if (preg_match('/\A[A-Za-z0-9_-]{1,191}\z/', $price) !== 1 || strlen(json_encode($options, JSON_THROW_ON_ERROR, 16)) > 16384) {
            throw ValidationException::withMessages(['billing' => ['Checkout parameters are invalid.']]);
        }
        $hash = $this->hasher->hash(['kind' => 'checkout', 'user_id' => (int) $subject->id, 'price' => $price, 'options' => $options]);

        return DB::transaction(function () use ($subject, $price, $hash): BillingMutationLease {
            $user = User::query()->lockForUpdate()->findOrFail($subject->id);
            if (app(BillingRoleEntitlements::class)->manualOverrideTier($user) !== null) {
                throw ValidationException::withMessages(['billing' => ['Your access has an administrator override. Contact support before purchasing a paid plan.']]);
            }
            if (config('billing.durable.enabled', false) || $user->subscriptions()->whereNotNull('billing_projection_attempt_id')->exists()) {
                throw ValidationException::withMessages(['billing' => ['This account requires coordinated billing checkout. Please retry after billing recovery is available.']]);
            }
            $state = BillingMutationState::query()->where('subject_user_id', $user->id)->lockForUpdate()->first();
            $state ??= BillingMutationState::query()->create(['subject_user_id' => $user->id, 'user_id' => $user->id])->refresh();
            if ($state->active_intent_id !== null) {
                $intent = BillingMutationIntent::query()->findOrFail($state->active_intent_id);
                if ($intent->subject_user_id !== (int) $user->id || $intent->kind !== 'checkout_guard'
                    || ! hash_equals($intent->request_hash, $hash) || $intent->desired_price_id !== $price) {
                    throw ValidationException::withMessages(['billing' => ['Another billing operation needs resolution before starting checkout.']]);
                }

                return new BillingMutationLease($state->id, $intent->id, $user->id, $state->owner_token ?? '', false);
            }
            $intent = BillingMutationIntent::query()->create(['operation_uuid' => (string) Str::uuid(),
                'subject_user_id' => $user->id, 'user_id' => $user->id, 'actor_user_id' => $user->id,
                'kind' => 'checkout_guard', 'policy' => 'legacy_checkout_guard', 'requested_customer_id' => $user->stripe_id,
                'desired_price_id' => $price, 'request_hash' => $hash, 'facts' => ['purpose' => 'user_checkout'], 'requested_at' => BillingClock::now()]);
            $owner = (string) Str::uuid();
            $state->update(['active_intent_id' => $intent->id, 'owner_token' => $owner,
                'lease_sequence' => $state->lease_sequence + 1, 'execution_started_at' => BillingClock::now(),
                'lease_expires_at' => BillingClock::now()->addSeconds(120), 'status' => 'possible_send', 'failure_category' => null]);
            $this->journal->record($state, $intent, 'checkout_possible_send', ['desired_price_id' => $price, 'policy' => 'legacy_checkout_guard'],
                hash('sha256', $intent->operation_uuid.':legacy_checkout'), $hash);
            // The caller keeps its injected SDK seam, but cannot reuse an older customer snapshot.
            $subject->setRawAttributes($user->getAttributes(), true);

            return new BillingMutationLease($state->id, $intent->id, $user->id, $owner, true);
        }, attempts: 3);
    }

    public function acknowledged(BillingMutationLease $owner, string $customer, string $session): void
    {
        $this->database->assert();
        if (preg_match('/\Acus_[A-Za-z0-9_]{1,187}\z/', $customer) !== 1 || preg_match('/\Acs_[A-Za-z0-9_]{1,188}\z/', $session) !== 1) {
            throw new LogicException('Checkout response identity is invalid.');
        }
        DB::transaction(function () use ($owner, $customer, $session): void {
            $user = User::query()->lockForUpdate()->findOrFail($owner->userId);
            [$state, $intent] = $this->owned($owner);
            if ($user->stripe_id !== $customer) {
                throw new LogicException('Checkout customer linkage changed.');
            }
            $this->journal->record($state, $intent, 'checkout_acknowledged', ['customer_id' => $customer, 'checkout_session_id' => $session]);
            $state->update(['customer_id' => $customer, 'status' => 'session_open', 'failure_category' => null]);
        }, attempts: 3);
    }

    public function uncertain(BillingMutationLease $owner): void
    {
        $this->database->assert();
        DB::transaction(function () use ($owner): void {
            User::query()->lockForUpdate()->findOrFail($owner->userId);
            [$state, $intent] = $this->owned($owner);
            if ($state->status !== 'possible_send') {
                return;
            }
            $this->journal->record($state, $intent, 'checkout_uncertain', ['category' => 'checkout_outcome_unknown']);
            $state->update(['status' => 'uncertain', 'failure_category' => 'checkout_outcome_unknown']);
        }, attempts: 3);
    }

    private function owned(BillingMutationLease $owner): array
    {
        $state = BillingMutationState::query()->lockForUpdate()->findOrFail($owner->stateId);
        $intent = BillingMutationIntent::query()->findOrFail($owner->intentId);
        if ($state->subject_user_id !== $owner->userId || $intent->subject_user_id !== $owner->userId
            || $state->active_intent_id !== $owner->intentId || $state->owner_token === null
            || ! hash_equals($state->owner_token, $owner->ownerToken)) {
            throw new LogicException('Checkout reservation ownership changed.');
        }

        return [$state, $intent];
    }
}
