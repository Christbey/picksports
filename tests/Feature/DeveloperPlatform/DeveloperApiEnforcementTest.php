<?php

use App\Models\DeveloperApiUsageRecord;
use App\Models\DeveloperEntitlementPolicy;
use App\Models\DeveloperOrganization;
use App\Models\DeveloperProduct;
use App\Services\DeveloperPlatform\DeveloperApiCredentialIssuer;
use Illuminate\Support\Facades\Auth;

function developerSandboxContext(array $credentialScopes = ['sandbox:read'], array $limits = ['requests_per_month' => 2]): array
{
    $organization = DeveloperOrganization::factory()->create();
    $product = DeveloperProduct::query()->firstOrCreate(
        ['code' => 'developer-sandbox'],
        [
            'name' => 'Developer Sandbox',
            'is_active' => true,
            'default_scopes' => ['sandbox:read'],
            'default_limits' => $limits,
        ],
    );
    $policy = DeveloperEntitlementPolicy::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
        'scopes' => ['sandbox:read'],
        'limits' => $limits,
    ]);
    $issued = app(DeveloperApiCredentialIssuer::class)->issue(
        $organization,
        'Sandbox credential',
        $credentialScopes,
    );

    return compact('organization', 'product', 'policy', 'issued');
}

it('keeps the developer sandbox separate from user authentication', function () {
    $this->getJson('/api/v2/developer/sandbox')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('enforces credential scopes and product entitlements before recording usage', function () {
    $context = developerSandboxContext(['other:read']);

    $this->withToken($context['issued']->plainTextToken)
        ->getJson('/api/v2/developer/sandbox')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'developer_scope_denied');

    expect(DeveloperApiUsageRecord::query()->count())->toBe(0);

    Auth::forgetGuards();
    $context = developerSandboxContext();
    $context['policy']->update(['status' => 'suspended']);

    $this->withToken($context['issued']->plainTextToken)
        ->getJson('/api/v2/developer/sandbox')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'developer_entitlement_denied');
});

it('atomically consumes quota records lineage and returns rate limit headers', function () {
    $context = developerSandboxContext();

    $first = $this->withHeader('X-Request-ID', 'developer-request-1')
        ->withToken($context['issued']->plainTextToken)
        ->getJson('/api/v2/developer/sandbox')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '2')
        ->assertHeader('X-RateLimit-Remaining', '1')
        ->assertHeader('X-RateLimit-Reset')
        ->assertHeader('RateLimit-Policy')
        ->assertJsonPath('data.mode', 'sandbox')
        ->assertJsonPath('data.organization_id', $context['organization']->public_id)
        ->assertJsonPath('data.credential_id', $context['issued']->credential->public_id)
        ->assertJsonPath('data.product', 'developer-sandbox')
        ->assertJsonPath('data.scope', 'sandbox:read')
        ->assertJsonPath('data.request_id', 'developer-request-1');

    expect($first->headers->get('X-Request-ID'))->toBe('developer-request-1');

    $this->withHeader('X-Request-ID', 'developer-request-2')
        ->withToken($context['issued']->plainTextToken)
        ->getJson('/api/v2/developer/sandbox')
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '0');

    $this->withHeader('X-Request-ID', 'developer-request-3')
        ->withToken($context['issued']->plainTextToken)
        ->getJson('/api/v2/developer/sandbox')
        ->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '2')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('X-RateLimit-Reset')
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'developer_quota_exceeded');

    expect(DeveloperApiUsageRecord::query()->count())->toBe(2);

    $usage = DeveloperApiUsageRecord::query()->orderBy('id')->first();

    expect($usage->developer_organization_id)->toBe($context['organization']->id)
        ->and($usage->developer_api_credential_id)->toBe($context['issued']->credential->id)
        ->and($usage->developer_product_id)->toBe($context['product']->id)
        ->and($usage->developer_entitlement_policy_id)->toBe($context['policy']->id)
        ->and($usage->request_id)->toBe('developer-request-1')
        ->and($usage->operation)->toBe('developer.sandbox.show')
        ->and($usage->scope)->toBe('sandbox:read')
        ->and($usage->units)->toBe(1);
});
