<?php

namespace App\Services;

use App\Models\BillingMutationIntent;
use App\Models\User;
use App\Services\Billing\BillingCheckoutReservations;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubscriptionCheckoutService
{
    /** @param array<string, mixed> $sessionOptions */
    public function createCheckoutUrl(User $user, string $stripePriceId, array $sessionOptions = []): string
    {
        $reservations = app(BillingCheckoutReservations::class);
        $lease = $reservations->reserve($user, $stripePriceId, $sessionOptions);
        if (! $lease->newlyAdmitted) {
            throw ValidationException::withMessages(['billing' => ['A checkout is already pending. Resolve that checkout before starting another; contact support if its outcome is unknown.']]);
        }
        $operation = BillingMutationIntent::query()->findOrFail($lease->intentId)->operation_uuid;
        $sessionOptions['metadata']['picksports_operation'] = $operation;
        $sessionOptions['subscription_data']['metadata']['picksports_operation'] = $operation;
        try {
            // The durable possible-send receipt is committed before SDK-hidden customer creation.
            $checkout = $user->newSubscription('default', $stripePriceId)->checkout($sessionOptions);
            $session = $checkout->asStripeCheckoutSession();
            $customer = is_string($session->customer) ? $session->customer : ($session->customer->id ?? null);
            if (! is_string($customer) || ! is_string($session->id) || ! is_string($checkout->url)) {
                throw new \LogicException('Checkout response is incomplete.');
            }
            $reservations->acknowledged($lease, $customer, $session->id);

            return $checkout->url;
        } catch (Throwable $failure) {
            // A lost acknowledgement is not permission to retry a charge-bearing operation.
            $reservations->uncertain($lease);
            throw $failure;
        }
    }
}
