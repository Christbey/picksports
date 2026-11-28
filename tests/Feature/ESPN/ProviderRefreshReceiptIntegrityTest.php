<?php

use App\Models\NFL\Team;
use App\Models\ProviderRefreshAttempt;
use App\Models\ProviderRefreshHead;
use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use App\Services\ProviderData\Refresh\RefreshContext;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/ProviderRefreshFixture.php';

beforeEach(function () {
    $this->team = Team::factory()->create(['espn_id' => '22']);
    enableSyntheticRefreshScope($this->team, 'injuries', 0);
    $this->coordinator = app(ProviderRefreshCoordinator::class);
});

it('guards terminal refresh attempts across quiet deletion and numeric entry points', function (string $method) {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $context->commit(true, [], fn () => 0);
    $attempt = ProviderRefreshAttempt::query()->sole();
    expect(fn () => match ($method) {
        'saveQuietly' => $attempt->forceFill(['error_code' => 'rewrite'])->saveQuietly(),
        'deleteQuietly' => $attempt->deleteQuietly(),
        'incrementQuietly' => $attempt->incrementQuietly('row_count'),
        'decrementQuietly' => $attempt->decrementQuietly('row_count'),
    })->toThrow(LogicException::class);
})->with(['saveQuietly', 'deleteQuietly', 'incrementQuietly', 'decrementQuietly']);

it('refuses a stale running instance after terminal completion', function () {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $stale = ProviderRefreshAttempt::query()->sole();
    $context->commit(true, [], fn () => 0);
    expect(fn () => $stale->forceFill(['outcome' => 'failed', 'completed_at' => now()])->saveQuietly())->toThrow(LogicException::class);
    expect($stale->fresh()->outcome)->toBe('applied');
});

it('refuses replacement of a running owner or lease even through quiet saves', function (string $field) {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $attempt = $context->attempt;
    $value = $field === 'owner_hash' ? str_repeat('b', 64) : now()->addDay();
    expect(fn () => $attempt->forceFill([$field => $value, 'outcome' => 'failed', 'completed_at' => now()])->saveQuietly())->toThrow(LogicException::class);
})->with(['owner_hash', 'expires_at']);

it('protects activated head identity counters and retention through quiet methods', function (string $method) {
    $head = ProviderRefreshHead::query()->sole();
    expect(fn () => match ($method) {
        'identity' => $head->forceFill(['target_fingerprint' => str_repeat('b', 64)])->saveQuietly(),
        'unreceipted' => $head->forceFill(['issued_sequence' => 1])->saveQuietly(),
        'deleteQuietly' => $head->deleteQuietly(),
        'incrementQuietly' => $head->incrementQuietly('issued_sequence'),
        'decrementQuietly' => $head->decrementQuietly('issued_sequence'),
    })->toThrow(LogicException::class);
})->with(['identity', 'unreceipted', 'deleteQuietly', 'incrementQuietly', 'decrementQuietly']);

it('rejects owner substitution without invoking the projection callback', function () {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $forged = new RefreshContext($this->coordinator, $this->team, $context->attempt, 'wrong', $context->scope);
    expect(fn () => $forged->commit(true, [], fn () => throw new LogicException('Projection must not run.')))->toThrow(RuntimeException::class, 'owner mismatch');
    expect(ProviderRefreshAttempt::query()->sole()->outcome)->toBeNull();
});

it('records expired attempt refusal without invoking the projection callback', function () {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $this->travel(121)->seconds();
    try {
        expect(fn () => $context->commit(true, [], fn () => throw new LogicException('Projection must not run.')))->toThrow(RuntimeException::class, 'expired');
        $context->finish();
        expect(ProviderRefreshAttempt::query()->sole()->outcome)->toBe('expired');
    } finally {
        $this->travelBack();
    }
});

it('retains the durable activated marker when the runtime flag is disabled', function () {
    config(['provider_refresh.enabled' => false]);
    expect(fn () => $this->coordinator->begin($this->team, 'injuries', 0))->toThrow(RuntimeException::class, 'cannot fall back');
    expect(ProviderRefreshHead::query()->sole()->issued_sequence)->toBe(0)
        ->and(ProviderRefreshAttempt::query()->count())->toBe(0);
});

it('refuses clone targets or absent reviewed target configuration before reservation', function (string $value) {
    config(['provider_refresh.write_target_fingerprint' => $value]);
    expect(fn () => $this->coordinator->begin($this->team, 'injuries', 0))->toThrow(RuntimeException::class);
    expect(ProviderRefreshAttempt::query()->count())->toBe(0);
})->with(['', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

it('refuses generic NBA injury scope activation even with a synthetic head', function () {
    $team = App\Models\NBA\Team::factory()->create(['espn_id' => '44']);
    enableSyntheticRefreshScope($team, 'injuries', 0);
    expect(fn () => $this->coordinator->begin($team, 'injuries', 0))->toThrow(RuntimeException::class, 'unsupported');
});

it('rolls back projection and terminal state on write failure while retaining a separate failure receipt', function () {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $before = $this->team->fresh()->getRawOriginal();
    try {
        $context->commit(true, [], function () {
            $this->team->update(['name' => 'Wrong']);
            throw new RuntimeException('Synthetic write failure.');
        });
    } catch (RuntimeException $error) {
        $context->fail($error);
    }
    expect($this->team->fresh()->getRawOriginal())->toBe($before)
        ->and(ProviderRefreshAttempt::query()->sole()->outcome)->toBe('failed')
        ->and(ProviderRefreshHead::query()->sole()->latest_applied_sequence)->toBe(0);
});

it('refuses an ordinary schema rollback without erasing activated history', function () {
    $migration = require database_path('migrations/2026_11_26_130000_create_provider_refresh_fencing.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'forward-only');
    expect(ProviderRefreshHead::query()->count())->toBe(1);
});

it('expires only explicit abandoned IDs with a dry run before apply and without provider requests', function () {
    Http::preventStrayRequests();
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $this->travel(121)->seconds();
    try {
        $this->artisan('provider:expire-refresh-attempts', ['ids' => [(string) $context->attempt->id]])->assertSuccessful();
        expect($context->attempt->fresh()->outcome)->toBeNull();
        $this->artisan('provider:expire-refresh-attempts', ['ids' => [(string) $context->attempt->id], '--apply' => true])->assertSuccessful();
        expect($context->attempt->fresh()->outcome)->toBe('expired');
        $this->artisan('provider:expire-refresh-attempts', ['ids' => [(string) $context->attempt->id], '--apply' => true])->assertSuccessful();
        expect(ProviderRefreshAttempt::query()->count())->toBe(1);
        Http::assertNothingSent();
    } finally {
        $this->travelBack();
    }
});

it('rejects an unbounded expiry selection before any audit mutation', function () {
    $this->artisan('provider:expire-refresh-attempts', ['ids' => array_map(strval(...), range(1, 101)), '--apply' => true])->assertFailed();
    expect(ProviderRefreshAttempt::query()->count())->toBe(0);
});

it('refuses an ordinary current running ORM save that claims applied without the private owner completion path', function () {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    $attempt = $context->attempt->fresh();
    expect(fn () => $attempt->forceFill(['outcome' => 'applied', 'completed_at' => now('UTC'),
        'observed' => true, 'reliable' => true, 'source_fingerprint' => str_repeat('a', 64)])->saveQuietly())->toThrow(LogicException::class);
});

it('refuses expired superseded wrong-owner and disabled direct owned completion', function (string $reason) {
    $context = $this->coordinator->begin($this->team, 'injuries', 0);
    if ($reason === 'expired') {
        $this->travel(121)->seconds();
    }
    if ($reason === 'superseded') {
        $this->coordinator->begin($this->team, 'injuries', 0);
    }
    if ($reason === 'disabled') {
        config(['provider_refresh.enabled' => false]);
    }
    $owner = $reason === 'wrong-owner' ? 'wrong' : $context->owner;
    try {
        expect(fn () => $context->attempt->fresh()->completeAppliedFromOwner($owner, ['completed_at' => now('UTC'),
            'observed' => true, 'reliable' => true, 'source_fingerprint' => str_repeat('a', 64)]))->toThrow(LogicException::class);
        expect($context->attempt->fresh()->outcome)->toBeNull();
    } finally {
        $this->travelBack();
    }
})->with(['expired', 'superseded', 'wrong-owner', 'disabled']);

it('requires bounded immutable recorded activation and drain attestations', function (string $field) {
    $head = ProviderRefreshHead::query()->sole();
    expect($head->hasRecordedAttestations())->toBeTrue();
    expect(fn () => $head->forceFill([$field => 'replacement'])->saveQuietly())->toThrow(LogicException::class);
    $head->getConnection()->table($head->getTable())->where('id', $head->id)->update([$field => '']);
    expect(fn () => $this->coordinator->begin($this->team, 'injuries', 0))->toThrow(RuntimeException::class);
    expect(ProviderRefreshAttempt::query()->count())->toBe(0);
})->with(['activation_operator', 'activation_reason', 'drain_reference', 'source_review_reference']);
