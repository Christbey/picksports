<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use App\Models\BillingReconciliationAttempt;
use App\Models\BillingWebhookReceipt;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class BillingWebhookAdmission
{
    public const TYPES = ['customer.subscription.created', 'customer.subscription.updated',
        'customer.subscription.deleted', 'customer.deleted'];

    public function __construct(private readonly BillingDatabaseBoundary $database, private readonly CanonicalPayloadHasher $hasher, private readonly BillingCustomerIdentity $customers) {}

    public function admit(array $payload, string $expectedType): BillingWebhookReceipt
    {
        $this->database->assert();
        $context = new BillingAccountContext;
        $identity = $this->identity($payload, $context, $expectedType);
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR, 64);
        } catch (JsonException) {
            throw new HttpException(422, 'Billing event structure is invalid.');
        }
        if (strlen($encoded) > min(1048576, max(1, (int) config('billing.maximum_event_bytes', 262144)))) {
            throw new HttpException(413, 'Billing event exceeds its byte limit.');
        }
        $hash = $this->hasher->hash($payload);
        for ($retry = 0; $retry < 3; $retry++) {
            // Outside any inherited snapshot: a conflict targets the ORIGINAL customer's lock.
            $prior = BillingWebhookReceipt::query()->where($context->scope())->where('event_id', $identity['event_id'])->first();
            if ($prior !== null && ($prior->account_id !== $context->accountId || $prior->livemode !== $context->livemode || $prior->event_id !== $identity['event_id'])) {
                throw new LogicException('Billing receipt lookup returned a different identity.');
            }
            try {
                [$receipt, $conflict] = DB::transaction(function () use ($context, $identity, $hash, $prior): array {
                    $key = [...$context->scope(), 'customer_id' => $prior?->customer_id ?? $identity['customer_id']];
                    $state = BillingCustomerReconciliation::query()->where($key)->lockForUpdate()->first();
                    $state ??= BillingCustomerReconciliation::query()->create($key);
                    if ($state->account_id !== $context->accountId || $state->livemode !== $context->livemode || $state->customer_id !== $key['customer_id']) {
                        throw new LogicException('Billing customer lookup returned a different identity.');
                    }
                    $users = $this->customers->users($key['customer_id']);
                    $receipt = BillingWebhookReceipt::query()->where($context->scope())
                        ->where('event_id', $identity['event_id'])->lockForUpdate()->first();
                    if ($receipt !== null) {
                        if ($receipt->account_id !== $context->accountId || $receipt->livemode !== $context->livemode || $receipt->event_id !== $identity['event_id']) {
                            throw new LogicException('Billing receipt lookup returned a different identity.');
                        }
                        if ((int) $receipt->reconciliation_id !== (int) $state->id) {
                            // A different customer's first admission committed after our initial read.
                            // Roll back and restart with its original customer lock, never reverse lock order.
                            throw new BillingAdmissionRace;
                        }
                        if (! hash_equals($receipt->payload_hash, $hash)) {
                            // Commit conflict evidence before returning refusal to the sender.
                            BillingReconciliationAttempt::query()->create([
                                'reconciliation_id' => $state->id, 'receipt_id' => $receipt->id,
                                'generation' => $state->generation, 'lease_sequence' => $state->lease_sequence,
                                'owner_token' => (string) Str::uuid(), 'kind' => 'admission_conflict', 'status' => 'conflict',
                                'started_at' => BillingClock::now(), 'completed_at' => BillingClock::now(), 'failure_category' => 'event_hash_conflict',
                                'details' => ['admitted_hash' => $receipt->payload_hash, 'conflicting_hash' => $hash],
                            ]);
                            $state->update(['status' => 'attention', 'failure_category' => 'event_hash_conflict',
                                'generation' => $state->generation + 1, 'owner_token' => null,
                                'lease_expires_at' => null, 'next_attempt_at' => null]);

                            return [$receipt, true];
                        }

                        return [$receipt, false];
                    }
                    $status = match ($users->count()) {
                        0 => 'pending_linkage', 1 => 'pending', default => 'attention'
                    };
                    $integrityHold = $state->failure_category === 'event_hash_conflict';
                    if ($integrityHold) {
                        $status = 'attention';
                    }
                    $state->update(['generation' => $state->generation + 1, 'status' => $status, 'attempts' => 0,
                        'user_id' => $users->count() === 1 ? $users->first()->id : null, 'next_attempt_at' => BillingClock::now(),
                        'failure_category' => $integrityHold ? 'event_hash_conflict' : ($users->count() > 1 ? 'ambiguous_customer' : ($users->isEmpty() ? 'unknown_customer' : null))]);
                    $receipt = BillingWebhookReceipt::query()->create([
                        ...$context->scope(), ...$identity, 'reconciliation_id' => $state->id,
                        'payload_hash' => $hash, 'received_at' => BillingClock::now(), 'generation' => $state->generation,
                        'status' => $status,
                    ]);

                    return [$receipt, false];
                }, attempts: 3);
                if ($conflict) {
                    throw new HttpException(409, 'Billing event identity conflicts with its admitted payload.');
                }

                return $receipt;
            } catch (UniqueConstraintViolationException|BillingAdmissionRace $error) {
                if ($retry === 2) {
                    throw $error;
                }
            }
        }
        throw new HttpException(503, 'Billing admission could not obtain a stable identity.');
    }

    private function identity(array $payload, BillingAccountContext $context, string $expectedType): array
    {
        if (! in_array($expectedType, self::TYPES, true) || ($payload['type'] ?? null) !== $expectedType
            || ! is_bool($payload['livemode'] ?? null) || $payload['livemode'] !== $context->livemode
            || (isset($payload['account']) && $payload['account'] !== $context->accountId)
            || isset($payload['context'])) {
            throw new HttpException(422, 'Billing event scope or type is invalid.');
        }
        $object = $payload['data']['object'] ?? null;
        $event = $payload['id'] ?? null;
        $created = $payload['created'] ?? null;
        if (! is_array($object) || ! $this->identifier($event, 'evt_') || ! is_int($created) || $created < 0
            || $created > 253402300799 || (isset($object['livemode']) && $object['livemode'] !== $context->livemode)) {
            throw new HttpException(422, 'Billing event identity is invalid.');
        }
        $deletedCustomer = $expectedType === 'customer.deleted';
        $customer = $deletedCustomer ? ($object['id'] ?? null) : ($object['customer'] ?? null);
        $subscription = $deletedCustomer ? null : ($object['id'] ?? null);
        if (! $this->identifier($customer, 'cus_') || (! $deletedCustomer && ! $this->identifier($subscription, 'sub_'))) {
            throw new HttpException(422, 'Billing customer or subscription identity is invalid.');
        }

        return ['event_id' => $event, 'event_type' => $expectedType, 'provider_created_at' => $created,
            'customer_id' => $customer, 'subscription_id' => $subscription];
    }

    private function identifier(mixed $value, string $prefix): bool
    {
        return is_string($value) && strlen($value) <= 191
            && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_]+\z/', $value) === 1;
    }
}
