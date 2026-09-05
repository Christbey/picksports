<?php

use App\Enums\DeveloperOrganizationRole;
use App\Models\DeveloperApiCredential;
use App\Models\DeveloperEntitlementPolicy;
use App\Models\DeveloperOrganization;
use App\Models\DeveloperOrganizationMembership;
use App\Models\DeveloperProduct;
use App\Models\User;
use App\Services\DeveloperPlatform\DeveloperApiCredentialAuthenticator;
use App\Services\DeveloperPlatform\DeveloperApiCredentialIssuer;
use App\Services\DeveloperPlatform\DeveloperApiUsageRecorder;
use App\Services\DeveloperPlatform\DeveloperEntitlementResolver;
use Illuminate\Support\Str;

it('models developer organizations, role memberships, products, and policies', function () {
    $owner = User::factory()->create();
    $organization = DeveloperOrganization::factory()->create(['created_by_user_id' => $owner->id]);
    $membership = DeveloperOrganizationMembership::factory()->create([
        'developer_organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => DeveloperOrganizationRole::Owner,
    ]);
    $product = DeveloperProduct::factory()->create(['code' => 'predictions-pro']);
    $policy = DeveloperEntitlementPolicy::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
    ]);

    expect(Str::isUlid($organization->public_id))->toBeTrue()
        ->and(Str::isUlid($product->public_id))->toBeTrue()
        ->and(Str::isUlid($policy->public_id))->toBeTrue()
        ->and($organization->getRouteKeyName())->toBe('public_id')
        ->and($membership->role)->toBe(DeveloperOrganizationRole::Owner)
        ->and($membership->organization->is($organization))->toBeTrue()
        ->and($membership->user->is($owner))->toBeTrue()
        ->and($policy->product->is($product))->toBeTrue();
});

it('issues hashed scoped credentials and authenticates the plaintext only once exposed', function () {
    $organization = DeveloperOrganization::factory()->create();
    $creator = User::factory()->create();
    $issued = app(DeveloperApiCredentialIssuer::class)->issue(
        $organization,
        'Production API',
        ['events:read', 'events:read', 'predictions:read'],
        $creator,
    );
    [$tokenPrefix, $secret] = explode('.', $issued->plainTextToken, 2);

    expect($issued->plainTextToken)->toStartWith('psa_')
        ->and($issued->credential->prefix)->toBe(substr($tokenPrefix, 4))
        ->and($issued->credential->secret_hash)->toBe(hash('sha256', $secret))
        ->and($issued->credential->secret_hash)->not->toBe($secret)
        ->and($issued->credential->scopes)->toBe(['events:read', 'predictions:read'])
        ->and($issued->credential->created_by_user_id)->toBe($creator->id)
        ->and($issued->credential->toArray())->not->toHaveKey('secret_hash');

    $authenticator = app(DeveloperApiCredentialAuthenticator::class);

    expect($authenticator->authenticate($issued->plainTextToken, 'events:write'))->toBeNull()
        ->and($authenticator->authenticate('not-a-token'))->toBeNull()
        ->and($authenticator->authenticate($issued->plainTextToken.'x'))->toBeNull();

    $authenticated = $authenticator->authenticate($issued->plainTextToken, 'events:read');

    expect($authenticated?->is($issued->credential))->toBeTrue()
        ->and($issued->credential->fresh()->last_used_at)->not->toBeNull();
});

it('rejects revoked expired and inactive-organization credentials', function () {
    $issuer = app(DeveloperApiCredentialIssuer::class);
    $authenticator = app(DeveloperApiCredentialAuthenticator::class);
    $organization = DeveloperOrganization::factory()->create();
    $revoked = $issuer->issue($organization, 'Revoked', ['events:read']);

    $issuer->revoke($revoked->credential);

    expect($authenticator->authenticate($revoked->plainTextToken))->toBeNull();

    $expired = $issuer->issue(
        $organization,
        'Expired',
        ['events:read'],
        expiresAt: now()->subMinute(),
    );

    expect($authenticator->authenticate($expired->plainTextToken))->toBeNull();

    $inactive = $issuer->issue($organization, 'Inactive org', ['events:read']);
    $organization->update(['status' => 'suspended']);

    expect($authenticator->authenticate($inactive->plainTextToken))->toBeNull();
});

it('resolves only effective product entitlement policies with the requested scope', function () {
    $organization = DeveloperOrganization::factory()->create();
    $product = DeveloperProduct::factory()->create(['code' => 'events-core']);
    $effective = DeveloperEntitlementPolicy::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
        'scopes' => ['events:read'],
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);
    DeveloperEntitlementPolicy::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
        'scopes' => ['*'],
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addMonth(),
    ]);
    $resolver = app(DeveloperEntitlementResolver::class);

    expect($resolver->resolve($organization, 'events-core', 'events:read')?->is($effective))->toBeTrue()
        ->and($resolver->resolve($organization, 'events-core', 'events:write'))->toBeNull()
        ->and($resolver->resolve($organization, 'unknown', 'events:read'))->toBeNull();

    $product->update(['is_active' => false]);

    expect($resolver->resolve($organization, 'events-core', 'events:read'))->toBeNull();
});

it('records immutable auditable usage with credential product and policy lineage', function () {
    $organization = DeveloperOrganization::factory()->create();
    $credential = DeveloperApiCredential::factory()->create([
        'developer_organization_id' => $organization->id,
    ]);
    $product = DeveloperProduct::factory()->create();
    $policy = DeveloperEntitlementPolicy::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
    ]);
    $record = app(DeveloperApiUsageRecorder::class)->record(
        organization: $organization,
        operation: 'events.index',
        credential: $credential,
        product: $product,
        policy: $policy,
        scope: 'events:read',
        units: 3,
        statusCode: 200,
        requestId: '01K2H7YEXAMPLE0000000000000',
        metadata: ['version' => 'v2'],
    );

    expect(Str::isUlid($record->public_id))->toBeTrue()
        ->and($record->organization->is($organization))->toBeTrue()
        ->and($record->credential->is($credential))->toBeTrue()
        ->and($record->product->is($product))->toBeTrue()
        ->and($record->entitlementPolicy->is($policy))->toBeTrue()
        ->and($record->units)->toBe(3)
        ->and($record->metadata)->toBe(['version' => 'v2']);

    expect(fn () => $record->update(['units' => 4]))
        ->toThrow(LogicException::class, 'usage records are immutable');
});

it('refuses to attribute usage to another organizations credential', function () {
    $organization = DeveloperOrganization::factory()->create();
    $otherCredential = DeveloperApiCredential::factory()->create();

    expect(fn () => app(DeveloperApiUsageRecorder::class)->record(
        organization: $organization,
        operation: 'events.index',
        credential: $otherCredential,
    ))->toThrow(InvalidArgumentException::class, 'does not belong');
});
