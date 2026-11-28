<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

it('refuses an unpersisted or retargeted owned group update without acknowledging success', function (string $mode) {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Original group', 'type' => 'bracket_pool']);
    $before = (array) DB::table('groups')->where('id', $group->id)->first();
    $dispatcher = Group::getEventDispatcher();
    Group::setEventDispatcher(clone $dispatcher);
    $reached = false;
    if ($mode === 'delete') {
        Group::saved(function (Group $row) use (&$reached) {
            $reached = true;
            $row->getConnection()->table('groups')->where('id', $row->id)->delete();
        });
    } else {
        Group::saving(function (Group $row) use (&$reached, $mode, $other) {
            $reached = true;
            if ($mode === 'cancel') {
                return false;
            }
            if ($mode === 'rewrite') {
                $row->name = 'Unrequested rewrite';
            } elseif ($mode === 'key') {
                $row->id = 900001;
            } elseif ($mode === 'owner') {
                $row->owner_id = $other->id;
            }

            return null;
        });
    }
    try {
        $response = $this->actingAs($owner)->patchJson('/api/v2/groups/'.$group->public_id, ['name' => 'Requested group']);
    } finally {
        Group::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBeTrue();
    $response->assertStatus(500);
    expect((array) DB::table('groups')->where('id', $group->id)->first())->toBe($before);
    expect(DB::table('groups')->where('id', 900001)->exists())->toBeFalse();
    Http::assertNothingSent();
})->with(['cancel', 'rewrite', 'delete', 'key', 'owner']);

it('preserves the actual owner update and foreign-owner refusal contract', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Original group', 'type' => 'bracket_pool']);
    $this->actingAs($other)->patchJson('/api/v2/groups/'.$group->public_id, ['name' => 'Foreign'])->assertNotFound();
    expect($group->fresh()->name)->toBe('Original group');
    $this->actingAs($owner)->patchJson('/api/v2/groups/'.$group->public_id, ['name' => 'Requested group'])->assertOk()->assertJsonPath('data.name', 'Requested group');
    expect($group->fresh()->name)->toBe('Requested group')->and($group->fresh()->owner_id)->toBe($owner->id);
    Http::assertNothingSent();
});

it('allows a failed group update to retry the same idempotency key after persistence recovers', function () {
    $owner = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Original group', 'type' => 'bracket_pool']);
    $dispatcher = Group::getEventDispatcher();
    Group::setEventDispatcher(clone $dispatcher);
    Group::saving(fn () => false);
    try {
        $this->actingAs($owner)->withHeader('Idempotency-Key', 'synthetic-group-save-retry')
            ->patchJson('/api/v2/groups/'.$group->public_id, ['name' => 'Requested group'])->assertStatus(500);
    } finally {
        Group::setEventDispatcher($dispatcher);
    }
    expect($group->fresh()->name)->toBe('Original group');
    $this->patchJson('/api/v2/groups/'.$group->public_id, ['name' => 'Requested group'])->assertOk()->assertJsonPath('data.name', 'Requested group');
    $stored = $group->fresh()->getRawOriginal();
    $this->patchJson('/api/v2/groups/'.$group->public_id, ['name' => 'Requested group'])->assertOk()->assertJsonPath('data.name', 'Requested group');
    expect($group->fresh()->getRawOriginal())->toBe($stored);
    Http::assertNothingSent();
});
