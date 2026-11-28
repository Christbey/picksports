<?php

use App\Http\Controllers\WebhookController;
use App\Models\User;
use App\Services\SubscriptionPeriodEndResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.billing_snapshot_writer' => config('database.connections.mysql')]);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('billing_snapshot_writer');
});

function billingSnapshotFixture(): array
{
    $user = User::factory()->create(['stripe_id' => 'cus_snapshot_synthetic']);
    $subscription = $user->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_snapshot_synthetic', 'stripe_status' => 'active',
        'stripe_price' => 'price_snapshot_synthetic', 'quantity' => 1,
    ]);
    $item = $subscription->items()->create([
        'stripe_id' => 'si_snapshot_synthetic', 'stripe_product' => 'prod_snapshot_synthetic',
        'stripe_price' => 'price_snapshot_synthetic', 'quantity' => 1,
    ]);
    $payload = ['data' => ['object' => [
        'id' => $subscription->stripe_id, 'customer' => $user->stripe_id,
        'status' => 'active', 'cancel_at_period_end' => true,
    ]]];

    return [$user, $subscription, $item, $payload];
}

it('detects subscription and item drift with current locked reads despite an older snapshot', function (string $target) {
    [$user, $subscription, $item, $payload] = billingSnapshotFixture();
    $controller = app(WebhookController::class);
    $snapshot = new ReflectionMethod($controller, 'subscriptionSnapshot');
    DB::beginTransaction();
    $before = $snapshot->invoke($controller, $user, $payload, false);
    $row = $target === 'subscription' ? $subscription : $item;
    DB::connection('billing_snapshot_writer')->table($row->getTable())->where('id', $row->id)->update(['quantity' => 9]);

    // Control: an ordinary repeatable-read snapshot demonstrably hides the committed change.
    expect($snapshot->invoke($controller, $user, $payload, false))->toBe($before)
        ->and($snapshot->invoke($controller, $user, $payload, true))->not->toBe($before);
    DB::commit();
})->with(['subscription', 'item']);

it('refuses standalone cancellation when another connection changed items during provider lookup', function () {
    [$user, $subscription, $item, $payload] = billingSnapshotFixture();
    $this->mock(SubscriptionPeriodEndResolver::class)->shouldReceive('resolve')->once()
        ->andReturnUsing(function () use ($item) {
            expect(DB::transactionLevel())->toBe(0);
            DB::connection('billing_snapshot_writer')->table($item->getTable())->where('id', $item->id)->update(['quantity' => 9]);

            return Carbon::createFromTimestamp(2100000000);
        });
    expect(fn () => app(WebhookController::class)->handleCustomerSubscriptionUpdated($payload))
        ->toThrow(RuntimeException::class, 'Billing subscription changed during period lookup; retry required');
    expect($subscription->fresh()->ends_at)->toBeNull()
        ->and($item->fresh()->quantity)->toBe(9);
});
