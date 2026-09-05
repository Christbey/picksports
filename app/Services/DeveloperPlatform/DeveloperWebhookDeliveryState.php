<?php

namespace App\Services\DeveloperPlatform;

use App\Models\DeveloperWebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeveloperWebhookDeliveryState
{
    public function markFailed(
        DeveloperWebhookDelivery $delivery,
        string $error,
        ?int $responseStatus = null,
    ): DeveloperWebhookDelivery {
        return DB::transaction(function () use ($delivery, $error, $responseStatus): DeveloperWebhookDelivery {
            $locked = DeveloperWebhookDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());
            $attempts = $locked->attempts + 1;
            $maxAttempts = max(1, (int) config('api.developer.webhooks.max_attempts', 5));
            $isDead = $attempts >= $maxAttempts;

            $locked->forceFill([
                'status' => $isDead ? 'dead' : 'retry',
                'attempts' => $attempts,
                'available_at' => $isDead ? $locked->available_at : now()->addSeconds($this->retryDelay($attempts)),
                'locked_at' => null,
                'last_attempt_at' => now(),
                'response_status' => $responseStatus,
                'last_error' => Str::limit($error, 2000, ''),
            ])->save();
            $locked->endpoint()->update(['last_failure_at' => now()]);

            return $locked->fresh();
        }, 3);
    }

    public function markDelivered(DeveloperWebhookDelivery $delivery, int $responseStatus): DeveloperWebhookDelivery
    {
        return DB::transaction(function () use ($delivery, $responseStatus): DeveloperWebhookDelivery {
            $locked = DeveloperWebhookDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());

            $locked->forceFill([
                'status' => 'delivered',
                'attempts' => $locked->attempts + 1,
                'locked_at' => null,
                'last_attempt_at' => now(),
                'delivered_at' => now(),
                'response_status' => $responseStatus,
                'last_error' => null,
            ])->save();
            $locked->endpoint()->update(['last_success_at' => now()]);

            return $locked->fresh();
        }, 3);
    }

    private function retryDelay(int $attempt): int
    {
        $backoff = array_values(array_map(
            fn (mixed $seconds): int => max(1, (int) $seconds),
            (array) config('api.developer.webhooks.retry_backoff_seconds', [60, 300, 900, 3600]),
        ));

        return $backoff[min($attempt - 1, count($backoff) - 1)] ?? 60;
    }
}
