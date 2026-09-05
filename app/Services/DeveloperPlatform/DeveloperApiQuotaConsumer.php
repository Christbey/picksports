<?php

namespace App\Services\DeveloperPlatform;

use App\DataTransferObjects\DeveloperPlatform\DeveloperApiQuotaConsumption;
use App\Exceptions\DeveloperApiQuotaExceeded;
use App\Models\DeveloperApiCredential;
use App\Models\DeveloperApiUsageRecord;
use App\Models\DeveloperEntitlementPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class DeveloperApiQuotaConsumer
{
    public function __construct(private readonly DeveloperApiUsageRecorder $usageRecorder) {}

    public function consume(
        DeveloperApiCredential $credential,
        DeveloperEntitlementPolicy $policy,
        string $operation,
        string $scope,
        string $requestId,
        int $units = 1,
    ): DeveloperApiQuotaConsumption {
        if ($units < 1) {
            throw new LogicException('Developer API quota units must be positive.');
        }

        return DB::transaction(function () use ($credential, $policy, $operation, $scope, $requestId, $units): DeveloperApiQuotaConsumption {
            $lockedPolicy = DeveloperEntitlementPolicy::query()
                ->with(['organization', 'product'])
                ->lockForUpdate()
                ->findOrFail($policy->getKey());

            if (! $this->isEffective($lockedPolicy)
                || $lockedPolicy->developer_organization_id !== $credential->developer_organization_id
                || ! $lockedPolicy->allowsScope($scope)) {
                throw new LogicException('The developer entitlement is no longer effective.');
            }

            $limit = $this->monthlyLimit($lockedPolicy);
            $periodStart = CarbonImmutable::now()->startOfMonth();
            $resetsAt = $periodStart->addMonth();
            $used = (int) DeveloperApiUsageRecord::query()
                ->where('developer_entitlement_policy_id', $lockedPolicy->getKey())
                ->where('occurred_at', '>=', $periodStart)
                ->where('occurred_at', '<', $resetsAt)
                ->sum('units');

            if ($used + $units > $limit) {
                throw new DeveloperApiQuotaExceeded($limit, $used, $resetsAt);
            }

            $usageRecord = $this->usageRecorder->record(
                organization: $lockedPolicy->organization,
                operation: $operation,
                credential: $credential,
                product: $lockedPolicy->product,
                policy: $lockedPolicy,
                scope: $scope,
                units: $units,
                requestId: $requestId,
                metadata: ['quota_period' => $periodStart->format('Y-m')],
            );
            $used += $units;

            return new DeveloperApiQuotaConsumption(
                limit: $limit,
                used: $used,
                remaining: max(0, $limit - $used),
                resetsAt: $resetsAt,
                usageRecord: $usageRecord,
            );
        }, 3);
    }

    private function monthlyLimit(DeveloperEntitlementPolicy $policy): int
    {
        $limit = (int) data_get($policy->limits, 'requests_per_month', 0);

        if ($limit < 1) {
            $limit = (int) data_get($policy->product?->default_limits, 'requests_per_month', 0);
        }

        if ($limit < 1) {
            throw new LogicException('The developer entitlement has no positive requests_per_month limit.');
        }

        return $limit;
    }

    private function isEffective(DeveloperEntitlementPolicy $policy): bool
    {
        return $policy->status === 'active'
            && $policy->organization?->isActive() === true
            && $policy->product?->is_active === true
            && ($policy->starts_at === null || $policy->starts_at->isPast())
            && ($policy->ends_at === null || $policy->ends_at->isFuture());
    }
}
