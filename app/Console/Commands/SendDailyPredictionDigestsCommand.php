<?php

namespace App\Console\Commands;

use App\Services\DailyDigestService;
use Illuminate\Console\Command;

class SendDailyPredictionDigestsCommand extends Command
{
    protected $signature = 'alerts:send-daily-digests';

    protected $description = 'Send daily prediction and player prop digest emails to opted-in users';

    public function __construct(
        private readonly DailyDigestService $dailyDigestService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Checking for due daily digest emails...');

        $sent = $this->dailyDigestService->sendDueDigests();

        $this->info("Sent {$sent} daily digest email(s).");

        return self::SUCCESS;
    }
}
