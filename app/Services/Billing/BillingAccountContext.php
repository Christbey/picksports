<?php

namespace App\Services\Billing;

use Laravel\Cashier\Cashier;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class BillingAccountContext
{
    public string $accountId;

    public bool $livemode;

    public string $apiOrigin;

    public function __construct()
    {
        $account = config('billing.account.platform_id');
        $mode = config('billing.account.livemode');
        $origin = config('billing.account.api_origin');
        if (! is_string($account) || preg_match('/\Aacct_[A-Za-z0-9_]{1,180}\z/', $account) !== 1
            || ! is_bool($mode) || ! is_string($origin)) {
            throw new HttpException(503, 'Durable billing account context is not configured.');
        }
        $parts = parse_url($origin);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
            || filter_var($origin, FILTER_VALIDATE_URL) === false
            || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || rtrim($origin, '/') !== 'https://api.stripe.com'
            || rtrim($origin, '/') !== rtrim(Cashier::$apiBaseUrl, '/')) {
            throw new HttpException(503, 'Durable billing API origin does not match the configured provider.');
        }
        $this->accountId = $account;
        $this->livemode = $mode;
        $this->apiOrigin = rtrim($origin, '/');
    }

    /** @return array{account_id:string,livemode:bool} */
    public function scope(): array
    {
        return ['account_id' => $this->accountId, 'livemode' => $this->livemode];
    }
}
