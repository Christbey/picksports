<?php

namespace App\Services\Billing;

use App\Services\Http\ApprovedHostGet;
use JsonException;
use Laravel\Cashier\Cashier;
use Throwable;

/** Read-only provider adapter. Never accepts caller URLs, Stripe credentials or persistence callbacks. */
final class BillingSnapshotCollector
{
    private int $calls = 0;

    private int $items = 0;

    private float $deadline;

    private array $headers;

    private BillingAccountContext $context;

    public function __construct(private readonly ApprovedHostGet $http, private readonly BillingFetchClock $clock) {}

    public function collect(array $request): array
    {
        if (! config('billing.durable.enabled', false)) {
            throw new BillingProviderFailure('provider_disabled');
        }
        $this->context = new BillingAccountContext;
        $seconds = (int) config('billing.fetch_budget_seconds', 60);
        if ($seconds < 1 || $seconds > 120 || array_diff(array_keys($request), ['account_id', 'livemode', 'customer_id', 'targets', 'customer_deletion_event_id']) !== []
            || ($request['account_id'] ?? null) !== $this->context->accountId || ($request['livemode'] ?? null) !== $this->context->livemode
            || ! $this->id($request['customer_id'] ?? null, 'cus_') || ! is_array($request['targets'] ?? null)
            || ! array_is_list($request['targets']) || count($request['targets']) > min(8, max(1, (int) config('billing.maximum_targets', 4)))) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        foreach ($request['targets'] as $target) {
            if (! $this->id($target, 'sub_')) {
                throw new BillingProviderFailure('provider_request_invalid');
            }
        }
        if (count(array_unique($request['targets'], SORT_STRING)) !== count($request['targets'])) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        $eventId = $request['customer_deletion_event_id'] ?? null;
        if ($eventId !== null && ! $this->id($eventId, 'evt_')) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        try {
            $client = Cashier::stripe();
        } catch (Throwable) {
            throw new BillingProviderFailure('provider_context_invalid');
        }
        $key = $client->getApiKey();
        if (! is_string($key) || $key === '' || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)
            || rtrim($client->getApiBase(), '/') !== $this->context->apiOrigin || $client->getStripeAccount() !== null) {
            throw new BillingProviderFailure('provider_context_invalid');
        }
        $this->headers = ['Authorization' => 'Bearer '.$key, 'Stripe-Version' => Cashier::STRIPE_VERSION, 'Accept' => 'application/json'];
        $this->deadline = $this->clock->now() + $seconds;
        $this->calls = $this->items = 0;
        $started = now()->getTimestamp();
        $account = $this->read('/v1/account');
        if (($account['id'] ?? null) !== $this->context->accountId || ($account['object'] ?? null) !== 'account') {
            throw new BillingProviderFailure('provider_scope_mismatch');
        }
        $deleted = false;
        $proof = null;
        if ($eventId !== null) {
            // A mode-bearing resource fetched with the effective key proves mode; account objects alone cannot.
            $event = $this->read('/v1/events/'.rawurlencode($eventId));
            if (($event['id'] ?? null) !== $eventId || ($event['object'] ?? null) !== 'event'
                || ($event['type'] ?? null) !== 'customer.deleted' || ($event['livemode'] ?? null) !== $this->context->livemode
                || (isset($event['account']) && $event['account'] !== $this->context->accountId)
                || isset($event['context']) || ($event['data']['object']['id'] ?? null) !== $request['customer_id']
                || ($event['data']['object']['object'] ?? null) !== 'customer') {
                throw new BillingProviderFailure('provider_scope_mismatch');
            }
            $customer = $this->read('/v1/customers/'.rawurlencode($request['customer_id']));
            if (($customer['id'] ?? null) !== $request['customer_id'] || ($customer['object'] ?? null) !== 'customer'
                || (isset($customer['deleted']) && ! is_bool($customer['deleted']))
                || (isset($customer['livemode']) && $customer['livemode'] !== $this->context->livemode)) {
                throw new BillingProviderFailure('provider_scope_mismatch');
            }
            $deleted = ($customer['deleted'] ?? false) === true;
            if ($deleted) {
                $proof = ['event_id' => $eventId, 'account_id' => $this->context->accountId,
                    'livemode' => $this->context->livemode, 'customer_id' => $request['customer_id'],
                    'customer_object' => 'customer', 'deleted' => true];
                $proof['fingerprint'] = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR));
            }
        }
        $subscriptions = [];
        if (! $deleted) {
            if ($request['targets'] === [] && $eventId === null) {
                throw new BillingProviderFailure('provider_mode_unproven');
            }
            foreach ($request['targets'] as $target) {
                $subscriptions[] = $this->subscription($this->read('/v1/subscriptions/'.rawurlencode($target)), $target, $request['customer_id']);
            }
        }
        $this->budget();

        return [...$this->context->scope(), 'customer_id' => $request['customer_id'], 'customer_deleted' => $deleted,
            'deletion_proof' => $proof, 'subscriptions' => $subscriptions, 'fetch_started_at' => $started,
            'fetch_completed_at' => now()->getTimestamp(), 'provider_calls' => $this->calls];
    }

    private function read(string $path): array
    {
        $this->budget();
        if (++$this->calls > min(16, max(1, (int) config('billing.maximum_provider_calls', 8)))) {
            throw new BillingProviderFailure('provider_call_limit');
        }
        try {
            $response = $this->http->get($this->context->apiOrigin.$path, ['api.stripe.com'],
                262144, headers: $this->headers, followRedirects: false);
        } catch (Throwable) {
            throw new BillingProviderFailure('provider_transport_failure');
        }
        $this->budget();
        if ($response->status() !== 200) {
            throw new BillingProviderFailure(match ($response->status()) {
                401, 403 => 'provider_auth_failure', 404 => 'provider_not_found', 429 => 'provider_rate_limited',
                default => 'provider_http_failure',
            });
        }
        try {
            $data = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BillingProviderFailure('provider_shape_invalid');
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new BillingProviderFailure('provider_shape_invalid');
        }

        return $data;
    }

    private function subscription(array $data, string $id, string $customer): array
    {
        $customerId = is_array($data['customer'] ?? null) ? ($data['customer']['id'] ?? null) : ($data['customer'] ?? null);
        if (($data['id'] ?? null) !== $id || ($data['object'] ?? null) !== 'subscription'
            || $customerId !== $customer || ($data['livemode'] ?? null) !== $this->context->livemode
            || ! in_array($data['status'] ?? null, ['active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'paused'], true)
            || ! is_bool($data['cancel_at_period_end'] ?? null) || ! array_key_exists('trial_end', $data)) {
            throw new BillingProviderFailure('provider_scope_mismatch');
        }
        $collection = $data['items'] ?? null;
        $items = [];
        $pages = 0;
        do {
            if (++$pages > min(4, max(1, (int) config('billing.maximum_item_pages', 3)))
                || ! is_array($collection) || ! is_array($collection['data'] ?? null) || ! array_is_list($collection['data'])
                || ! is_bool($collection['has_more'] ?? null)) {
                throw new BillingProviderFailure('provider_inventory_incomplete');
            }
            foreach ($collection['data'] as $item) {
                if (++$this->items > min(100, max(1, (int) config('billing.maximum_items', 100)))) {
                    throw new BillingProviderFailure('provider_item_limit');
                }
                if (! is_array($item) || ! $this->id($item['id'] ?? null, 'si_') || isset($items[$item['id']])
                    || (isset($item['subscription']) && $item['subscription'] !== $id)
                    || ! is_array($item['price'] ?? null) || ! $this->id($item['price']['id'] ?? null)
                    || ! $this->id(is_array($item['price']['product'] ?? null) ? ($item['price']['product']['id'] ?? null) : ($item['price']['product'] ?? null))
                    || (isset($item['quantity']) && (! is_int($item['quantity']) || $item['quantity'] < 0 || $item['quantity'] > 2147483647))
                    || (isset($item['price']['livemode']) && $item['price']['livemode'] !== $this->context->livemode)) {
                    throw new BillingProviderFailure('provider_shape_invalid');
                }
                $items[$item['id']] = ['id' => $item['id'], 'price' => $item['price']['id'],
                    'product' => is_array($item['price']['product']) ? $item['price']['product']['id'] : $item['price']['product'],
                    'quantity' => $item['quantity'] ?? null, 'current_period_end' => $this->epoch($item, 'current_period_end')];
            }
            if (! $collection['has_more']) {
                break;
            }
            if ($pages >= min(4, max(1, (int) config('billing.maximum_item_pages', 3)))) {
                throw new BillingProviderFailure('provider_inventory_incomplete');
            }
            if ($collection['data'] === [] || $this->items >= min(100, max(1, (int) config('billing.maximum_items', 100)))) {
                throw new BillingProviderFailure('provider_inventory_incomplete');
            }
            $last = $collection['data'][array_key_last($collection['data'])]['id'];
            $collection = $this->read('/v1/subscription_items?'.http_build_query([
                'subscription' => $id, 'limit' => 100, 'starting_after' => $last,
            ], '', '&', PHP_QUERY_RFC3986));
        } while (true);
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if ($items === [] && ! in_array($data['status'], ['canceled', 'incomplete_expired'], true)) {
            throw new BillingProviderFailure('provider_inventory_incomplete');
        }
        $type = $metadata['type'] ?? $metadata['name'] ?? 'default';
        if (! is_string($type) || $type === '' || mb_strlen($type) > 255 || preg_match('/[\x00-\x1f\x7f]/', $type)) {
            throw new BillingProviderFailure('provider_shape_invalid');
        }
        $trial = $this->epoch($data, 'trial_end');
        if ($data['cancel_at_period_end'] && ! ($trial !== null && $trial > now()->getTimestamp())
            && ($items === [] || collect($items)->contains(fn ($item) => $item['current_period_end'] === null))) {
            throw new BillingProviderFailure('provider_period_incomplete');
        }
        $operation = $metadata['picksports_operation'] ?? null;
        $operation = is_string($operation) && preg_match('/\A[0-9a-f-]{36}\z/', $operation) === 1 ? $operation : null;
        $invoiceId = is_array($data['latest_invoice'] ?? null) ? ($data['latest_invoice']['id'] ?? null) : ($data['latest_invoice'] ?? null);
        $invoiceStatus = null;
        if ($operation !== null && $invoiceId !== null) {
            if (! $this->id($invoiceId, 'in_')) {
                throw new BillingProviderFailure('provider_shape_invalid');
            }
            $invoice = $this->read('/v1/invoices/'.$invoiceId);
            $parent = $invoice['parent']['subscription_details']['subscription'] ?? ($invoice['subscription'] ?? null);
            if (($invoice['id'] ?? null) !== $invoiceId || ($invoice['object'] ?? null) !== 'invoice'
                || ($invoice['customer'] ?? null) !== $customer || ($invoice['livemode'] ?? null) !== $this->context->livemode || $parent !== $id
                || ! in_array($invoice['status'] ?? null, ['draft', 'open', 'paid', 'void', 'uncollectible'], true)) {
                throw new BillingProviderFailure('provider_invoice_unbound');
            }
            $invoiceStatus = $invoice['status'];
        }
        ksort($items, SORT_STRING);

        return ['id' => $id, 'customer_id' => $customer, 'livemode' => $this->context->livemode,
            'operation_uuid' => $operation, 'invoice_id' => $operation !== null ? $invoiceId : null, 'invoice_status' => $invoiceStatus,
            'type' => $type, 'status' => $data['status'], 'created' => $this->epoch($data, 'created', true),
            'trial_end' => $trial, 'cancel_at_period_end' => $data['cancel_at_period_end'],
            'cancel_at' => $this->epoch($data, 'cancel_at'), 'canceled_at' => $this->epoch($data, 'canceled_at'),
            'ended_at' => $this->epoch($data, 'ended_at'), 'items' => array_values($items)];
    }

    private function epoch(array $data, string $field, bool $required = false): ?int
    {
        $value = $data[$field] ?? null;
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_int($value) || $value < 0 || $value > 253402300799) {
            throw new BillingProviderFailure('provider_shape_invalid');
        }

        return $value;
    }

    private function id(mixed $value, string $prefix = ''): bool
    {
        return is_string($value) && strlen($value) <= 191
            && preg_match('/\A'.preg_quote($prefix, '/').'[A-Za-z0-9_]+\z/', $value) === 1;
    }

    private function budget(): void
    {
        if ($this->clock->now() >= $this->deadline) {
            throw new BillingProviderFailure('provider_timeout');
        }
    }
}
