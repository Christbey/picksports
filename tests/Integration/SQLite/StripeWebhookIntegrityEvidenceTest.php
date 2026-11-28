<?php

use App\Http\Controllers\WebhookController;
use App\Models\SubscriptionTier;
use App\Models\User;
use App\Services\Admin\TierPermissionSyncService;
use App\Services\SubscriptionPeriodEndResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class);

function billingIntegrityPayload(string $eventId, int $created, string $status = 'active'): array
{
    return [
        'id' => $eventId,
        'created' => $created,
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_integrity_synthetic',
            'customer' => 'cus_integrity_synthetic',
            'status' => $status,
            'cancel_at_period_end' => false,
            'items' => ['data' => [[
                'id' => 'si_integrity_synthetic',
                'price' => ['id' => 'price_integrity_synthetic', 'product' => 'prod_integrity_synthetic'],
                'quantity' => 1,
            ]]],
        ]],
    ];
}

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config()->set('founding_users.enabled', false);
    SubscriptionTier::factory()->create(['name' => 'Free', 'slug' => 'free', 'is_default' => true, 'permissions' => [], 'features' => []]);
    SubscriptionTier::factory()->create([
        'name' => 'Integrity synthetic', 'slug' => 'integrity_synthetic',
        'stripe_price_id_monthly' => 'price_integrity_synthetic',
        'permissions' => [], 'features' => [],
    ]);
});

// Characterization evidence for BILL-04, to become rejection/rollback regressions with remediation.
it('demonstrates an older active update can reactivate a newer canceled subscription', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    $controller = app(WebhookController::class);
    $older = billingIntegrityPayload('evt_integrity_older', 100);
    $controller->handleCustomerSubscriptionCreated($older);
    $newer = billingIntegrityPayload('evt_integrity_newer', 200, 'canceled');
    $controller->handleCustomerSubscriptionDeleted($newer);
    expect($user->fresh()->subscribed())->toBeFalse();

    $controller->handleCustomerSubscriptionUpdated($older);

    expect($user->fresh()->subscription()->stripe_status)->toBe('active')
        ->and($user->fresh()->subscribed())->toBeTrue()
        ->and($user->fresh()->hasRole('integrity_synthetic'))->toBeTrue();
});

it('rolls back vendor subscription writes when role synchronization fails', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    $realRoleSync = app(TierPermissionSyncService::class);
    $this->mock(TierPermissionSyncService::class)
        ->shouldReceive('syncTierRolePermissions')->once()
        ->andReturnUsing(function ($tier) use ($realRoleSync) {
            $realRoleSync->syncTierRolePermissions($tier);
            throw new RuntimeException('synthetic role synchronization failure');
        });

    expect(fn () => app(WebhookController::class)->handleCustomerSubscriptionUpdated(
        billingIntegrityPayload('evt_integrity_partial', 300)
    ))->toThrow(RuntimeException::class, 'synthetic role synchronization failure');

    expect($user->fresh()->subscriptions()->count())->toBe(0)
        ->and(SubscriptionItem::query()->count())->toBe(0)
        ->and($user->fresh()->hasRole('integrity_synthetic'))->toBeFalse()
        ->and(Role::query()->where('name', 'integrity_synthetic')->exists())->toBeFalse();
});

it('rejects ambiguous local customer identity before subscription writes', function () {
    User::factory()->count(2)->create(['stripe_id' => 'cus_integrity_synthetic']);

    expect(fn () => app(WebhookController::class)->handleCustomerSubscriptionUpdated(
        billingIntegrityPayload('evt_integrity_ambiguous', 300)
    ))->toThrow(LogicException::class, 'Ambiguous billing customer identity');

    expect(Subscription::query()->count())->toBe(0);
});

it('rolls back existing subscription and item changes when role synchronization fails', function (string $operation) {
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    app(WebhookController::class)->handleCustomerSubscriptionCreated(billingIntegrityPayload('evt_seed', 100));
    $before = $user->fresh()->subscription()->getAttributes();
    $items = $user->fresh()->subscription()->items()->get()->map->getAttributes()->all();
    $this->mock(TierPermissionSyncService::class)->shouldReceive('syncTierRolePermissions')
        ->once()->andThrow(new RuntimeException('synthetic role synchronization failure'));
    $payload = billingIntegrityPayload('evt_failure', 200);
    $payload['data']['object']['items']['data'][0]['quantity'] = 2;
    $method = 'handleCustomerSubscription'.ucfirst($operation);

    expect(fn () => app(WebhookController::class)->{$method}($payload))
        ->toThrow(RuntimeException::class, 'synthetic role synchronization failure');

    expect($user->fresh()->subscription()->getAttributes())->toBe($before)
        ->and($user->fresh()->subscription()->items()->get()->map->getAttributes()->all())->toBe($items)
        ->and($user->fresh()->hasRole('integrity_synthetic'))->toBeTrue();
})->with(['created', 'updated', 'deleted']);

it('resolves a cancellation period before its transaction and preserves the resolved end', function (?int $trialEnd, ?int $resolvedEnd) {
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    app(WebhookController::class)->handleCustomerSubscriptionCreated(billingIntegrityPayload('evt_seed', 100));
    $baselineLevel = $user->getConnection()->transactionLevel();
    $futureTrial = $trialEnd !== null && $trialEnd > time();
    $resolver = $this->mock(SubscriptionPeriodEndResolver::class);
    if ($futureTrial) {
        $resolver->shouldNotReceive('resolve');
    } else {
        $resolver->shouldReceive('resolve')->once()->andReturnUsing(function () use ($user, $baselineLevel, $resolvedEnd) {
            // RefreshDatabase itself may own an outer test transaction; the handler has not opened one.
            expect($user->getConnection()->transactionLevel())->toBe($baselineLevel);

            return $resolvedEnd === null ? null : Carbon::createFromTimestamp($resolvedEnd);
        });
    }
    $payload = billingIntegrityPayload('evt_cancel', 200);
    $payload['data']['object']['cancel_at_period_end'] = true;
    if ($trialEnd !== null) {
        $payload['data']['object']['trial_end'] = $trialEnd;
    }
    app(WebhookController::class)->handleCustomerSubscriptionUpdated($payload);

    $expectedEnd = $futureTrial ? $trialEnd : $resolvedEnd;
    expect($user->fresh()->subscription()->getRawOriginal('ends_at'))->toBe(
        $expectedEnd === null ? null : Carbon::createFromTimestamp($expectedEnd)->format('Y-m-d H:i:s')
    );
})->with([
    'normal period' => [null, 2100000000],
    'missing period' => [null, null],
    'future trial' => [2200000000, null],
    'expired trial uses current period' => [100, 2100000000],
]);

it('refuses local drift during period lookup before applying the webhook', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    app(WebhookController::class)->handleCustomerSubscriptionCreated(billingIntegrityPayload('evt_seed', 100));
    $this->mock(SubscriptionPeriodEndResolver::class)->shouldReceive('resolve')->once()
        ->andReturnUsing(function () use ($user) {
            $user->subscriptions()->update(['quantity' => 7]);

            return Carbon::createFromTimestamp(2100000000);
        });
    $payload = billingIntegrityPayload('evt_drift', 200);
    $payload['data']['object']['cancel_at_period_end'] = true;
    expect(fn () => app(WebhookController::class)->handleCustomerSubscriptionUpdated($payload))
        ->toThrow(RuntimeException::class, 'Billing subscription changed during period lookup; retry required');
    expect($user->fresh()->subscription()->quantity)->toBe(7)
        ->and($user->fresh()->subscription()->ends_at)->toBeNull();
});

class BillingBoundaryOtherRole extends Role
{
    protected $connection = 'billing_boundary_other';
}

it('refuses permission model connection mismatch before writes', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    config()->set('database.connections.billing_boundary_other', config('database.connections.sqlite'));
    config()->set('permission.models.role', BillingBoundaryOtherRole::class);
    expect(fn () => app(WebhookController::class)->handleCustomerSubscriptionUpdated(billingIntegrityPayload('evt_other', 200)))
        ->toThrow(LogicException::class, 'Billing models must use the same database connection');
    expect($user->subscriptions()->count())->toBe(0);
});

class BillingNoRemoteSubscription extends Subscription
{
    protected $table = 'subscriptions';

    public function getForeignKey()
    {
        return 'subscription_id';
    }

    public function currentPeriodEnd(DateTimeZone|string|int|null $timezone = null): ?CarbonInterface
    {
        throw new RuntimeException('Vendor remote period path must not be invoked under a transaction');
    }
}

it('preserves non UTC trial raw dates even when the trial expires while acquiring the transaction', function () {
    $originalModel = Cashier::$subscriptionModel;
    Cashier::useSubscriptionModel(BillingNoRemoteSubscription::class);
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    $trialEnd = time() + 60;
    $payload = billingIntegrityPayload('evt_expiring_trial', 200);
    $payload['data']['object']['trial_end'] = $trialEnd;
    $payload['data']['object']['cancel_at_period_end'] = true;
    $this->mock(SubscriptionPeriodEndResolver::class)->shouldNotReceive('resolve');
    Event::listen(TransactionBeginning::class,
        function () use ($trialEnd) {
            Carbon::setTestNow(Carbon::createFromTimestamp($trialEnd + 86400));
        });

    try {
        expect(config('app.timezone'))->not->toBe('UTC');
        app(WebhookController::class)->handleCustomerSubscriptionUpdated($payload);
        expect($user->fresh()->subscription()->getRawOriginal('ends_at'))->toBe(
            Carbon::createFromTimestamp($trialEnd)->format('Y-m-d H:i:s')
        );
    } finally {
        Carbon::setTestNow();
        Cashier::useSubscriptionModel($originalModel);
    }
});

it('refuses an inherited transaction before preparing or writing a webhook', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_integrity_synthetic']);
    $this->mock(SubscriptionPeriodEndResolver::class)->shouldNotReceive('resolve');
    $user->getConnection()->beginTransaction();
    try {
        expect(fn () => app(WebhookController::class)->handleCustomerSubscriptionUpdated(billingIntegrityPayload('evt_outer', 400)))
            ->toThrow(LogicException::class, 'Billing webhook requires a standalone database transaction');
        expect($user->subscriptions()->count())->toBe(0);
    } finally {
        $user->getConnection()->rollBack();
    }
});

afterEach(function () {
    // Standalone test fixtures cannot leak into a later RefreshDatabase suite.
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});
