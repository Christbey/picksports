<?php

namespace App\Console\Commands\DeveloperPlatform;

use App\Models\DeveloperMeterBatch;
use App\Services\DeveloperPlatform\BillingMeterExportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BuildDeveloperMeterBatchCommand extends Command
{
    protected $signature = 'developer-platform:build-meter-batch
        {--from= : Inclusive ISO-8601 period start; defaults to yesterday at 00:00 UTC}
        {--to= : Exclusive ISO-8601 period end; defaults to today at 00:00 UTC}
        {--meter=api_request_units : Provider-neutral meter code}
        {--json : Emit the persisted batch payload as JSON}';

    protected $description = 'Aggregate immutable developer API usage into an idempotent billing meter batch without exporting it.';

    public function handle(BillingMeterExportService $exportService): int
    {
        try {
            $today = CarbonImmutable::now('UTC')->startOfDay();
            $from = $this->option('from')
                ? CarbonImmutable::parse((string) $this->option('from'))->utc()
                : $today->subDay();
            $to = $this->option('to')
                ? CarbonImmutable::parse((string) $this->option('to'))->utc()
                : $today;

            $payload = $exportService->prepare($from, $to, (string) $this->option('meter'));
            $batch = DeveloperMeterBatch::query()
                ->where('public_id', $payload->batchId)
                ->with('items')
                ->sole();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Meter batch %s is pending: %d units across %d usage records and %d line items.',
                $batch->public_id,
                $batch->total_units,
                $batch->usage_record_count,
                $batch->items->count(),
            ));
            $this->line('No billing provider was contacted.');
        }

        return self::SUCCESS;
    }
}
