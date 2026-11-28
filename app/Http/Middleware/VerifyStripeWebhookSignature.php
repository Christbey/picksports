<?php

namespace App\Http\Middleware;

use Closure;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

class VerifyStripeWebhookSignature extends VerifyWebhookSignature
{
    public function handle($request, Closure $next)
    {
        $secret = config('cashier.webhook.secret');
        abort_unless(is_string($secret) && trim($secret) !== '', 503, 'Billing webhook is not configured.');

        if (config('billing.durable.enabled', false)) {
            $maximum = min(1048576, max(1, (int) config('billing.maximum_event_bytes', 262144)));
            $length = $request->header('Content-Length');
            abort_if(is_string($length) && ctype_digit($length) && (float) $length > $maximum, 413);
            // PHP/proxies may already buffer input; this caps original bytes before vendor JSON verification.
            abort_if(strlen($request->getContent()) > $maximum, 413);
        }

        return parent::handle($request, $next);
    }
}
