<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\BillingFinancialScanner;
use Illuminate\Console\Command;
use Throwable;

final class ScanBillingMutationsCommand extends Command
{
    protected $signature = 'billing:scan-mutations {--limit=10} {--apply}';

    protected $description = 'Enqueue bounded durable billing mutations without provider calls';

    public function handle(BillingFinancialScanner $scanner): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/\A[1-9][0-9]{0,2}\z/', $limit) !== 1 || (int) $limit > 100) {
            return self::INVALID;
        }
        try {
            $this->line(json_encode($scanner->scan((int) $limit, (bool) $this->option('apply')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Billing mutation scan failed safely.');

            return self::FAILURE;
        }
    }
}
