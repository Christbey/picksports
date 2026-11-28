<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\BillingRecoveryScanner;
use Illuminate\Console\Command;
use Throwable;

class ScanBillingReconciliationCommand extends Command
{
    protected $signature = 'billing:scan-reconciliation {--limit=10 : Maximum due customers and cache flags per pass, 1-100} {--apply : Claim and enqueue existing pending work; otherwise list only}';

    protected $description = 'Recover bounded durable billing work without fetching provider data in the scanner';

    public function handle(BillingRecoveryScanner $scanner): int
    {
        $raw = $this->option('limit');
        if (! is_string($raw) || ! preg_match('/\A[1-9][0-9]{0,2}\z/', $raw) || (int) $raw > 100) {
            $this->error('Limit must be an integer from 1 through 100.');

            return self::INVALID;
        }
        try {
            $result = $scanner->scan((int) $raw, (bool) $this->option('apply'));
        } catch (Throwable) {
            $this->error('Billing scan failed safely; inspect configured context and durable ledger.');

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
