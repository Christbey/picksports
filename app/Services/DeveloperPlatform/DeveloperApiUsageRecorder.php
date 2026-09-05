<?php

namespace App\Services\DeveloperPlatform;

use App\Models\DeveloperApiCredential;
use App\Models\DeveloperApiUsageRecord;
use App\Models\DeveloperEntitlementPolicy;
use App\Models\DeveloperOrganization;
use App\Models\DeveloperProduct;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DeveloperApiUsageRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        DeveloperOrganization $organization,
        string $operation,
        ?DeveloperApiCredential $credential = null,
        ?DeveloperProduct $product = null,
        ?DeveloperEntitlementPolicy $policy = null,
        ?string $scope = null,
        int $units = 1,
        ?int $statusCode = null,
        ?string $requestId = null,
        array $metadata = [],
        ?DateTimeInterface $occurredAt = null,
    ): DeveloperApiUsageRecord {
        $operation = trim($operation);

        if ($operation === '' || $units < 1) {
            throw new InvalidArgumentException('Usage records require an operation and at least one unit.');
        }

        if ($credential !== null && $credential->developer_organization_id !== $organization->getKey()) {
            throw new InvalidArgumentException('The credential does not belong to the developer organization.');
        }

        if ($policy !== null && $product === null) {
            $product = $policy->product;
        }

        if ($policy !== null && ($policy->developer_organization_id !== $organization->getKey()
            || ($product !== null && $policy->developer_product_id !== $product->getKey()))) {
            throw new InvalidArgumentException('The entitlement policy does not match the usage context.');
        }

        return $organization->usageRecords()->create([
            'developer_api_credential_id' => $credential?->getKey(),
            'developer_product_id' => $product?->getKey(),
            'developer_entitlement_policy_id' => $policy?->getKey(),
            'request_id' => $requestId ?: (string) Str::ulid(),
            'operation' => $operation,
            'scope' => $scope,
            'units' => $units,
            'status_code' => $statusCode,
            'occurred_at' => $occurredAt ?? now(),
            'metadata' => $metadata,
        ]);
    }
}
