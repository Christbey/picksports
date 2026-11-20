<?php

use App\Models\Group;
use App\Models\GroupJoinLink;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function relationshipGroupLinkFixture(): Group
{
    return Group::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Relationship fixture', 'type' => 'bracket_pool', 'sport' => 'cbb', 'season' => 2026]);
}

it('enforces the declared one-to-one group join link relation at the database boundary', function () {
    $group = relationshipGroupLinkFixture();
    GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $group->owner_id]);

    expect(fn () => GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $group->owner_id]))->toThrow(QueryException::class);
});

it('refuses to tighten cardinality over duplicate links without deleting either record', function () {
    Schema::table('group_join_links', fn ($table) => $table->dropUnique('group_join_links_group_id_unique'));
    $group = relationshipGroupLinkFixture();
    GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $group->owner_id]);
    GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $group->owner_id]);
    $migration = require database_path('migrations/2026_11_19_210000_enforce_one_join_link_per_group.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'duplicate group links require explicit review')
        ->and(DB::table('group_join_links')->where('group_id', $group->id)->count())->toBe(2);
});
