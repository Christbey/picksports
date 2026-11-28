<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Compatibility adapter for unmarked/default-OFF rows; canonical conversion is fenced during its possible send. */
final class BillingLegacyOperation
{
    public function __construct(private readonly BillingMutationBoundary $database, private readonly BillingMutationJournal $journal) {}

    public function run(User $subject, string $kind, callable $operation): mixed
    {
        $this->database->assert($subject);
        if (! in_array($kind, ['cancel', 'resume', 'swap'], true)) {
            throw new \LogicException('Unsupported legacy billing operation.');
        }
        [$id, $intentId, $owner] = DB::transaction(function () use ($subject, $kind): array {
            $user = User::query()->lockForUpdate()->findOrFail($subject->id);
            $rows = $user->subscriptions()->lockForUpdate()->limit(9)->get();
            if (config('billing.durable.enabled', false) || $rows->count() > 8 || $rows->contains(fn ($row) => $row->billing_projection_attempt_id !== null)) {
                throw ValidationException::withMessages(['billing' => ['This subscription requires coordinated billing. No provider operation was started.']]);
            }
            if ($kind === 'swap' && app(BillingRoleEntitlements::class)->manualOverrideTier($user) !== null) {
                throw ValidationException::withMessages(['billing' => ['An administrator access override must be resolved before changing a paid plan.']]);
            }
            $state = BillingMutationState::query()->where('subject_user_id', $user->id)->lockForUpdate()->first();
            $state ??= BillingMutationState::query()->create(['subject_user_id' => $user->id, 'user_id' => $user->id])->refresh();
            if ($state->active_intent_id !== null) {
                throw ValidationException::withMessages(['billing' => ['Resolve the existing billing operation before starting another.']]);
            }
            $uuid = (string) Str::uuid();
            $intent = BillingMutationIntent::query()->create(['operation_uuid' => $uuid, 'subject_user_id' => $user->id,
                'user_id' => $user->id, 'actor_user_id' => $user->id, 'kind' => 'legacy_'.$kind, 'policy' => 'installed_cashier_legacy',
                'requested_customer_id' => $user->stripe_id, 'request_hash' => hash('sha256', $uuid), 'requested_at' => BillingClock::now()]);
            $owner = (string) Str::uuid();
            $state->update(['active_intent_id' => $intent->id, 'customer_id' => $user->stripe_id, 'status' => 'possible_send',
                'owner_token' => $owner, 'lease_sequence' => $state->lease_sequence + 1,
                'execution_started_at' => BillingClock::now(), 'lease_expires_at' => BillingClock::now()->addSeconds(120)]);
            $this->journal->record($state, $intent, 'legacy_possible_send', ['requested_kind' => $kind, 'policy' => 'installed_cashier_legacy'], hash('sha256', $uuid.':legacy'));

            $subject->setRawAttributes($user->getAttributes(), true);
            $subject->setRelation('subscriptions', $rows);

            return [$state->id, $intent->id, $owner];
        }, attempts: 3);
        try {
            $result = $operation(); // Existing SDK policy, outside every application SQL transaction.
            $this->finish($subject->id, $id, $intentId, $owner, true);

            return $result;
        } catch (Throwable $error) {
            $this->finish($subject->id, $id, $intentId, $owner, false);
            throw $error;
        }
    }

    private function finish(int $userId, int $id, int $intentId, string $owner, bool $success): void
    {
        DB::transaction(function () use ($userId, $id, $intentId, $owner, $success): void {
            User::query()->lockForUpdate()->findOrFail($userId);
            $state = BillingMutationState::query()->lockForUpdate()->findOrFail($id);
            if ($state->subject_user_id !== $userId || $state->active_intent_id !== $intentId || $state->owner_token === null || ! hash_equals($state->owner_token, $owner)) {
                throw new \LogicException('Legacy operation ownership changed; outcome requires review.');
            }
            $intent = BillingMutationIntent::query()->findOrFail($intentId);
            $this->journal->record($state, $intent, $success ? 'legacy_helper_returned' : 'legacy_outcome_unknown', ['category' => $success ? 'legacy_helper_returned' : 'financial_outcome_unknown']);
            $state->update(['status' => $success ? 'completed' : 'uncertain', 'active_intent_id' => $success ? null : $intentId,
                'owner_token' => null, 'lease_expires_at' => null, 'failure_category' => $success ? null : 'financial_outcome_unknown']);
        }, attempts: 3);
    }
}
