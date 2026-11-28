<?php

namespace App\Services\Billing;

use App\Models\BillingCustomerReconciliation;
use Illuminate\Database\Eloquent\Builder;

/** SQL leases are authoritative; this scanner never performs a provider read. */
final class BillingRecoveryScanner
{
    public function __construct(private readonly BillingDatabaseBoundary $database,
        private readonly BillingJobHandoff $jobs, private readonly BillingPermissionCacheRepair $cache) {}

    public function due(): Builder
    {
        $this->database->assert();
        $context = new BillingAccountContext;

        return BillingCustomerReconciliation::query()->where($context->scope())
            ->whereIn('status', ['pending', 'pending_linkage', 'processing'])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', BillingClock::now()->format('Y-m-d H:i:s.u')))
            ->where(fn ($query) => $query->whereNull('owner_token')->orWhere('lease_expires_at', '<=', BillingClock::now()->format('Y-m-d H:i:s.u')))
            ->orderByRaw('COALESCE(next_attempt_at, created_at) ASC')->orderBy('id');
    }

    public function scan(int $limit = 10, bool $apply = false): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Billing scan limit must be 1 through 100.');
        }
        if (! config('billing.durable.enabled', false)) {
            return ['due' => [], 'cache' => [], 'enqueued' => 0, 'repaired' => 0];
        }
        $ids = $this->due()->limit($limit)->pluck('id')->all();
        $context = new BillingAccountContext;
        $repairs = BillingCustomerReconciliation::query()->where($context->scope())
            ->where('cache_invalidation_pending', true)->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        $result = ['due' => $ids, 'cache' => $repairs->modelKeys(), 'enqueued' => 0, 'repaired' => 0];
        if (! $apply) {
            return $result;
        }
        foreach ($repairs as $state) {
            $repaired = $this->cache->repair($state);
            $result['repaired'] += (int) $repaired;
            if (! $repaired) {
                // Rotate failed repair work fairly without clearing or overwriting a newer committed flag.
                BillingCustomerReconciliation::query()->whereKey($state->id)
                    ->where('cache_generation', $state->cache_generation)->where('cache_lease_sequence', $state->cache_lease_sequence)
                    ->where('cache_invalidation_pending', true)->update(['updated_at' => BillingClock::now()->format('Y-m-d H:i:s.u')]);
            }
        }
        foreach ($ids as $id) {
            $result['enqueued'] += (int) $this->jobs->dispatch((int) $id);
        }

        return $result;
    }
}
