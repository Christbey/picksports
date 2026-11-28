<?php

namespace App\Services\Billing;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final class BillingSnapshotProcessRunner
{
    public function __construct(private readonly BillingDatabaseBoundary $database, private readonly BillingSnapshotProcessFactory $processes) {}

    public function run(array $request, ?int $maximumSeconds = null): array
    {
        $this->database->assert();
        if (PHP_SAPI !== 'cli') {
            throw new BillingProviderFailure('provider_runtime_unsupported');
        }
        $seconds = (int) config('billing.fetch_budget_seconds', 60);
        if ($maximumSeconds !== null) {
            $seconds = min($seconds, $maximumSeconds);
        }
        $input = json_encode($request, JSON_THROW_ON_ERROR, 16);
        if ($seconds < 1 || $seconds > 120 || strlen($input) > 16384) {
            throw new BillingProviderFailure('provider_request_invalid');
        }
        $process = $this->processes->create();
        $process->setInput($input);
        $process->setTimeout($seconds);
        $output = $errors = '';
        try {
            $process->run(function (string $type, string $chunk) use (&$output, &$errors, $process): void {
                if ($type === 'out') {
                    if (strlen($output) + strlen($chunk) > 1048576) {
                        throw new BillingProviderFailure('provider_output_limit');
                    }
                    $output .= $chunk;
                    $process->clearOutput();
                } else {
                    if (strlen($errors) + strlen($chunk) > 8192) {
                        throw new BillingProviderFailure('provider_output_limit');
                    }
                    $errors .= $chunk;
                    $process->clearErrorOutput();
                }
            });
            try {
                $envelope = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new BillingProviderFailure('provider_protocol_failure');
            }
            if (! is_array($envelope) || ! $process->isSuccessful() || ($envelope['ok'] ?? null) !== true || ! is_array($envelope['data'] ?? null)) {
                $category = $envelope['category'] ?? 'provider_process_failure';
                throw new BillingProviderFailure(is_string($category) && preg_match('/\Aprovider_[a-z_]{1,80}\z/', $category) ? $category : 'provider_process_failure');
            }

            return $envelope['data'];
        } catch (ProcessTimedOutException) {
            throw new BillingProviderFailure('provider_timeout');
        } catch (BillingProviderFailure $error) {
            throw $error;
        } catch (Throwable) {
            throw new BillingProviderFailure('provider_process_failure');
        } finally {
            // The reviewed child executes no subprocesses: stopping this PHP child stops the complete fetch.
            if ($process->isRunning()) {
                $process->stop(0.2, 9);
            }
        }
    }
}
