<?php

namespace App\Services\Billing;

use Laravel\Cashier\Cashier;

/** Private fixed-operation adapter. No SQL, local models, provider configuration writes or blind retry. */
final class BillingFinancialCollector
{
    private array $headers;

    private BillingAccountContext $context;

    private int $calls = 0;

    private float $deadline;

    public function __construct(private readonly BillingProviderHttp $http, private readonly BillingFetchClock $clock) {}

    public function execute(array $request): array
    {
        if (! config('billing.durable.enabled', false)) {
            throw new BillingProviderFailure('provider_disabled');
        }
        $this->context = new BillingAccountContext;
        $this->calls = 0;
        $this->deadline = $this->clock->now() + 60;
        if (($request['account_id'] ?? null) !== $this->context->accountId || ($request['livemode'] ?? null) !== $this->context->livemode
            || ! in_array($request['operation'] ?? null, ['prepare', 'send', 'checkout_read'], true)) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        $client = Cashier::stripe();
        $key = $client->getApiKey();
        if (! is_string($key) || $key === '' || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)
            || rtrim($client->getApiBase(), '/') !== $this->context->apiOrigin || $client->getStripeAccount() !== null) {
            throw new BillingProviderFailure('provider_context_invalid');
        }
        $this->headers = ['Authorization' => 'Bearer '.$key, 'Stripe-Version' => Cashier::STRIPE_VERSION,
            'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        $account = $this->read('/v1/account');
        if (($account['object'] ?? null) !== 'account' || ($account['id'] ?? null) !== $this->context->accountId) {
            throw new BillingProviderFailure('provider_scope_mismatch');
        }
        if ($request['operation'] === 'prepare') {
            return $this->prepare($request);
        }
        if ($request['operation'] === 'send') {
            $prepared = $request['prepared'] ?? null;
            if (! is_array($prepared) || ! is_string($prepared['path'] ?? null) || ! is_array($prepared['form'] ?? null)
                || ! is_string($request['step_key'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $request['step_key']) !== 1
                || ! hash_equals((string) ($request['request_hash'] ?? ''), $this->hash($prepared))) {
                throw new BillingProviderFailure('provider_request_invalid');
            }
            $this->validatePrepared($prepared);
            // Re-read bounded provider preconditions and key mode immediately before the single POST.
            $fresh = $this->prepare(['kind' => $prepared['kind'], 'operation_uuid' => $prepared['form']['metadata']['picksports_operation'] ?? '00000000-0000-4000-8000-000000000000',
                'customer_id' => $prepared['customer_id'] ?? null,
                'subscription_id' => str_starts_with($prepared['path'], '/v1/subscriptions/') ? substr($prepared['path'], strlen('/v1/subscriptions/')) : null,
                'price_id' => $prepared['price_id'] ?? null, 'email' => $prepared['form']['email'] ?? null, 'name' => $prepared['form']['name'] ?? null]);
            if (! hash_equals($request['request_hash'], $fresh['request_hash'])) {
                throw new BillingProviderFailure('provider_preparation_changed');
            }

            $data = $this->call('POST', $prepared['path'], $prepared['form'], ['Idempotency-Key' => $request['step_key']]);
            if ($prepared['kind'] !== 'portal' && ($data['metadata']['picksports_operation'] ?? null) !== ($prepared['form']['metadata']['picksports_operation'] ?? null)) {
                throw new BillingProviderFailure('provider_operation_unbound');
            }

            if ($prepared['kind'] === 'portal') {
                $configuration = is_array($data['configuration'] ?? null) ? ($data['configuration']['id'] ?? null) : ($data['configuration'] ?? null);
                if ($configuration !== $prepared['form']['configuration']) {
                    throw new BillingProviderFailure('provider_portal_unrestricted');
                }
            }
            if ($prepared['kind'] === 'checkout' && ($data['mode'] ?? null) !== 'subscription') {
                throw new BillingProviderFailure('provider_checkout_unbound');
            }

            return $this->acknowledgement($prepared['kind'], $data, $prepared['customer_id'] ?? null);
        }
        $customer = $this->customer($request['customer_id'] ?? null);
        if ($request['operation'] === 'checkout_read') {
            $id = $this->id($request['checkout_session_id'] ?? null, 'cs_');
            $data = $this->read('/v1/checkout/sessions/'.$id);
            $this->scope($data, 'checkout.session', $id);
            if (($data['customer'] ?? null) !== $customer['id']) {
                throw new BillingProviderFailure('provider_scope_mismatch');
            }

            if (($data['metadata']['picksports_operation'] ?? null) !== ($request['operation_uuid'] ?? null)
                || ! in_array($data['status'] ?? null, ['open', 'complete', 'expired'], true)
                || ! in_array($data['payment_status'] ?? null, ['paid', 'unpaid', 'no_payment_required'], true)) {
                throw new BillingProviderFailure('provider_checkout_unbound');
            }
            $subscription = is_array($data['subscription'] ?? null) ? ($data['subscription']['id'] ?? null) : ($data['subscription'] ?? null);
            if ($subscription !== null) {
                $subscription = $this->id($subscription, 'sub_');
            }

            return ['id' => $id, 'customer_id' => $customer['id'], 'status' => $data['status'], 'payment_status' => $data['payment_status'],
                'subscription_id' => $subscription, 'url' => $data['status'] === 'open' ? $this->url($data['url'] ?? null, 'checkout.stripe.com') : null];
        }
        throw new BillingProviderFailure('provider_request_invalid');
    }

    private function prepare(array $r): array
    {
        $kind = $r['kind'] ?? null;
        if (! in_array($kind, ['cancel', 'resume', 'swap', 'customer', 'checkout', 'portal'], true)
            || preg_match('/\A[0-9a-f-]{36}\z/', (string) ($r['operation_uuid'] ?? '')) !== 1) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        if (in_array($kind, ['customer', 'checkout', 'swap'], true) && Cashier::$calculatesTaxes) {
            throw new BillingProviderFailure('provider_tax_policy_unsupported');
        }
        $metadata = ['picksports_operation' => $r['operation_uuid']];
        $priceId = in_array($kind, ['customer', 'checkout', 'swap'], true) ? $this->price($r['price_id'] ?? null) : null;
        if ($kind === 'customer') {
            if (! is_string($r['email'] ?? null) || strlen($r['email']) > 254 || ! filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                throw new BillingProviderFailure('provider_request_invalid');
            }

            if (! is_string($r['name'] ?? null) || mb_strlen($r['name']) > 255 || preg_match('/[\x00-\x1f\x7f]/', $r['name'])) {
                throw new BillingProviderFailure('provider_request_invalid');
            }

            return $this->prepared($kind, '/v1/customers', ['email' => $r['email'], 'name' => $r['name'], 'metadata' => $metadata], null, $priceId);
        }
        $customer = $this->customer($r['customer_id'] ?? null);
        if ($kind === 'checkout') {
            $price = $this->id($r['price_id'] ?? null, 'price_');
            foreach (['/v1/subscriptions?customer='.$customer['id'].'&status=all&limit=100', '/v1/checkout/sessions?customer='.$customer['id'].'&status=open&limit=100'] as $path) {
                $list = $this->read($path);
                if (($list['object'] ?? null) !== 'list' || ($list['has_more'] ?? null) !== false || ! is_array($list['data'] ?? null) || count($list['data']) > 100) {
                    throw new BillingProviderFailure('provider_inventory_incomplete');
                }
                foreach ($list['data'] as $row) {
                    if (($row['customer'] ?? null) !== $customer['id'] || ($row['livemode'] ?? null) !== $this->context->livemode) {
                        throw new BillingProviderFailure('provider_scope_mismatch');
                    }
                    if (($row['object'] ?? null) === 'checkout.session' || ! in_array($row['status'] ?? null, ['canceled', 'incomplete_expired'], true)) {
                        throw new BillingProviderFailure('provider_existing_purchase');
                    }
                }
            }

            return $this->prepared($kind, '/v1/checkout/sessions', ['mode' => 'subscription', 'customer' => $customer['id'],
                'line_items' => [['price' => $price, 'quantity' => 1]], 'success_url' => BillingReturnUrl::route('subscription.success'),
                'cancel_url' => BillingReturnUrl::route('subscription.plans'), 'metadata' => $metadata, 'subscription_data' => ['metadata' => ['name' => 'default', 'type' => 'default', ...$metadata]]], $customer['id'], $priceId);
        }
        if ($kind === 'portal') {
            $config = $this->id(config('billing.portal.configuration_id'), 'bpc_');
            $data = $this->read('/v1/billing_portal/configurations/'.$config);
            $this->scope($data, 'billing_portal.configuration', $config);
            if (($data['active'] ?? null) !== true || ($data['features']['subscription_update']['enabled'] ?? null) !== false) {
                throw new BillingProviderFailure('provider_portal_unrestricted');
            }

            return $this->prepared($kind, '/v1/billing_portal/sessions', ['customer' => $customer['id'], 'configuration' => $config,
                'return_url' => BillingReturnUrl::route('subscription.manage')], $customer['id']);
        }
        $id = $this->id($r['subscription_id'] ?? null, 'sub_');
        $subscription = $this->read('/v1/subscriptions/'.$id);
        $this->scope($subscription, 'subscription', $id);
        if (($subscription['customer'] ?? null) !== $customer['id'] || ! in_array($subscription['status'] ?? null, ['active', 'trialing', 'past_due', 'unpaid'], true)) {
            throw new BillingProviderFailure('provider_subscription_not_mutable');
        }
        if (in_array($kind, ['resume', 'swap'], true) && (! array_key_exists('trial_end', $subscription)
            || ($subscription['trial_end'] !== null && (! is_int($subscription['trial_end']) || $subscription['trial_end'] < 0 || $subscription['trial_end'] > 253402300799)))) {
            throw new BillingProviderFailure('provider_shape_invalid');
        }
        $form = ['metadata' => $metadata, 'cancel_at_period_end' => $kind === 'cancel' ? 'true' : 'false'];
        if ($kind === 'resume' && ($subscription['cancel_at_period_end'] ?? null) !== true) {
            throw new BillingProviderFailure('provider_resume_not_scheduled');
        }
        if ($kind === 'resume') {
            $this->assertResumeGrace($subscription);
        }
        if ($kind !== 'cancel') {
            $trial = $subscription['trial_end'] ?? null;
            $form['trial_end'] = is_int($trial) && $trial > BillingClock::now()->getTimestamp() ? $trial : 'now';
        }
        if ($kind === 'swap') {
            $items = $subscription['items'] ?? [];
            if (($items['has_more'] ?? null) !== false || count($items['data'] ?? []) !== 1) {
                throw new BillingProviderFailure('provider_swap_shape_unsupported');
            }
            $item = $items['data'][0];
            $price = $this->id($r['price_id'] ?? null, 'price_');
            if (($item['price']['id'] ?? null) === $price) {
                throw new BillingProviderFailure('provider_same_plan');
            }
            if (($item['price']['recurring']['usage_type'] ?? null) !== 'licensed' || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 100) {
                throw new BillingProviderFailure('provider_swap_shape_unsupported');
            }
            $form += ['items' => [['price' => $price, 'quantity' => $item['quantity']], ['id' => $this->id($item['id'] ?? null, 'si_'), 'deleted' => 'true']],
                'payment_behavior' => 'default_incomplete', 'proration_behavior' => 'always_invoice', 'expand' => ['latest_invoice.confirmation_secret']];
        }

        return $this->prepared($kind, '/v1/subscriptions/'.$id, $form, $customer['id'], $priceId);
    }

    private function assertResumeGrace(array $subscription): void
    {
        $now = BillingClock::now()->getTimestamp();
        $trial = $subscription['trial_end'] ?? null;
        if (is_int($trial) && $trial > $now && $trial <= 253402300799) {
            return;
        }
        $items = $subscription['items'] ?? null;
        if (! is_array($items) || ($items['has_more'] ?? null) !== false || ! is_array($items['data'] ?? null)
            || ! array_is_list($items['data']) || $items['data'] === [] || count($items['data']) > 100) {
            throw new BillingProviderFailure('provider_inventory_incomplete');
        }
        $ends = [];
        foreach ($items['data'] as $item) {
            $end = is_array($item) ? ($item['current_period_end'] ?? null) : null;
            if (! is_int($end) || $end < 0 || $end > 253402300799) {
                throw new BillingProviderFailure('provider_period_incomplete');
            }
            $ends[] = $end;
        }
        if (max($ends) <= $now) {
            throw new BillingProviderFailure('provider_resume_grace_expired');
        }
    }

    private function prepared(string $kind, string $path, array $form, ?string $customer = null, ?string $price = null): array
    {
        $p = ['kind' => $kind, 'path' => $path, 'form' => $form, 'customer_id' => $customer, 'price_id' => $price];

        return ['prepared' => $p, 'request_hash' => $this->hash($p)];
    }

    private function validatePrepared(array $p): void
    {
        $path = match ($p['kind'] ?? null) {
            'customer' => '/v1/customers', 'checkout' => '/v1/checkout/sessions', 'portal' => '/v1/billing_portal/sessions',
            'cancel', 'resume', 'swap' => '/v1/subscriptions/'.$this->id(substr($p['path'], strlen('/v1/subscriptions/')), 'sub_'),
            default => throw new BillingProviderFailure('provider_request_invalid'),
        };
        $keys = match ($p['kind']) {
            'customer' => ['email', 'name', 'metadata'],
            'checkout' => ['mode', 'customer', 'line_items', 'success_url', 'cancel_url', 'metadata', 'subscription_data'],
            'portal' => ['customer', 'configuration', 'return_url'],
            'cancel' => ['metadata', 'cancel_at_period_end'],
            'resume' => ['metadata', 'cancel_at_period_end', 'trial_end'],
            'swap' => ['metadata', 'cancel_at_period_end', 'trial_end', 'items', 'payment_behavior', 'proration_behavior', 'expand'],
        };
        if (array_diff(array_keys($p['form']), $keys) !== [] || array_diff($keys, array_keys($p['form'])) !== []) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        if ($p['kind'] !== 'portal' && (array_keys($p['form']['metadata'] ?? []) !== ['picksports_operation']
            || preg_match('/\A[0-9a-f-]{36}\z/', (string) ($p['form']['metadata']['picksports_operation'] ?? '')) !== 1)) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        if ($p['kind'] === 'portal' && (($p['form']['configuration'] ?? null) !== config('billing.portal.configuration_id')
            || ($p['form']['return_url'] ?? null) !== BillingReturnUrl::route('subscription.manage'))) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        if ($p['kind'] === 'checkout' && (($p['form']['success_url'] ?? null) !== BillingReturnUrl::route('subscription.success')
            || ($p['form']['cancel_url'] ?? null) !== BillingReturnUrl::route('subscription.plans') || ($p['form']['mode'] ?? null) !== 'subscription')) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        if ($p['path'] !== $path || strlen(json_encode($p['form'], JSON_THROW_ON_ERROR, 16)) > 16384) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
    }

    private function acknowledgement(string $kind, array $data, ?string $customer): array
    {
        $object = match ($kind) {
            'customer' => 'customer', 'checkout' => 'checkout.session', 'portal' => 'billing_portal.session', default => 'subscription'
        };
        $prefix = match ($kind) {
            'customer' => 'cus_', 'checkout' => 'cs_', 'portal' => 'bps_', default => 'sub_'
        };
        $id = $this->id($data['id'] ?? null, $prefix);
        $this->scope($data, $object, $id);
        if ($kind !== 'customer' && ($data['customer'] ?? null) !== $customer) {
            throw new BillingProviderFailure('provider_scope_mismatch');
        }
        $result = ['id' => $id, 'customer_id' => $kind === 'customer' ? $id : $customer, 'status' => $data['status'] ?? null];
        if (in_array($kind, ['checkout', 'portal'], true)) {
            $result['url'] = $this->url($data['url'] ?? null, $kind === 'checkout' ? 'checkout.stripe.com' : 'billing.stripe.com');
        }
        $invoice = $data['latest_invoice'] ?? null;
        if (is_array($invoice)) {
            $parent = $invoice['parent']['subscription_details']['subscription'] ?? ($invoice['subscription'] ?? null);
            if (($invoice['object'] ?? null) !== 'invoice' || ($invoice['customer'] ?? null) !== $customer
                || ($invoice['livemode'] ?? null) !== $this->context->livemode || $parent !== $id) {
                throw new BillingProviderFailure('provider_invoice_unbound');
            }
            if (is_string($invoice['hosted_invoice_url'] ?? null)) {
                $result['invoice_url'] = $this->url($invoice['hosted_invoice_url'], 'invoice.stripe.com');
            }
            $result['invoice_id'] = $this->id($invoice['id'] ?? null, 'in_');
            $result['invoice_status'] = $invoice['status'] ?? null;
        }

        return $result;
    }

    private function scope(array $data, string $object, string $id): void
    {
        if (($data['id'] ?? null) !== $id || ($data['object'] ?? null) !== $object || ($data['livemode'] ?? null) !== $this->context->livemode) {
            throw new BillingProviderFailure('provider_scope_mismatch');
        }
    }

    private function price(mixed $id): string
    {
        $id = $this->id($id, 'price_');
        $price = $this->read('/v1/prices/'.$id);
        $this->scope($price, 'price', $id);
        if (($price['active'] ?? null) !== true || ($price['type'] ?? null) !== 'recurring'
            || ($price['recurring']['usage_type'] ?? null) !== 'licensed') {
            throw new BillingProviderFailure('provider_price_unsupported');
        }

        return $id;
    }

    private function customer(mixed $id): array
    {
        $id = $this->id($id, 'cus_');
        $data = $this->read('/v1/customers/'.$id);
        $this->scope($data, 'customer', $id);
        if (($data['deleted'] ?? false) === true) {
            throw new BillingProviderFailure('provider_customer_deleted');
        }

        return $data;
    }

    private function id(mixed $id, string $prefix): string
    {
        if (! is_string($id) || strlen($id) > 191 || ! str_starts_with($id, $prefix) || preg_match('/\A[A-Za-z0-9_]+\z/', $id) !== 1 || strlen($id) <= strlen($prefix)) {
            throw new BillingProviderFailure('provider_request_invalid');
        }

        return $id;
    }

    private function url(mixed $url, string $host): string
    {
        $p = is_string($url) ? parse_url($url) : false;
        if (! is_string($url) || strlen($url) > 4096 || ! is_array($p) || ($p['scheme'] ?? null) !== 'https' || ($p['host'] ?? null) !== $host
            || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new BillingProviderFailure('provider_url_invalid');
        }

        return $url;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR, 16));
    }

    private function read(string $path): array
    {
        return $this->call('GET', $path);
    }

    private function call(string $method, string $path, ?array $form = null, array $headers = []): array
    {
        if (++$this->calls > 8 || $this->clock->now() >= $this->deadline) {
            throw new BillingProviderFailure('provider_call_budget');
        }

        return $this->http->request($method, $path, $headers + $this->headers, $form);
    }
}
