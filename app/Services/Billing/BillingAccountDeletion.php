<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingMutationState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Refuses deletion when provider termination or an in-flight creation is unproved. */
final class BillingAccountDeletion
{
    public function __construct(private readonly BillingMutationBoundary $database) {}

    public function delete(User $subject): void
    {
        $this->database->assert($subject);
        DB::transaction(function () use ($subject): void {
            // Checkout admission takes this same current user lock before any SDK work.
            $user = User::query()->lockForUpdate()->findOrFail($subject->id);
            // No customer/reconciliation lock is taken here: any retained billing linkage
            // refuses deletion, including locally canceled subscriptions and expired leases.
            $hasBilling = $user->stripe_id !== null
                || $user->subscriptions()->exists()
                || BillingCustomerReconciliation::query()->where('user_id', $user->id)->exists()
                || BillingMutationState::query()->where('subject_user_id', $user->id)
                    ->where(function ($query): void {
                        $query->whereNotNull('active_intent_id')->orWhereNotNull('customer_id')
                            ->orWhere('status', '!=', 'idle');
                    })->exists();
            if ($hasBilling) {
                throw ValidationException::withMessages(['billing' => ['Your account has billing activity that must be resolved before deletion. Manage or cancel your subscription, then contact support to verify provider termination and complete account deletion.']]);
            }
            $user->delete();
        }, attempts: 3);
    }
}
