<?php

use App\Models\SubscriptionTier;
use App\Services\Admin\TierPermissionSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('refuses a tier from another connection without granting a colliding primary tier', function () {
    Permission::findOrCreate('view-nba-predictions', 'web');
    $primary = SubscriptionTier::factory()->create(['slug' => 'primary_only', 'name' => 'Primary', 'permissions' => ['view-nba-predictions'], 'features' => []]);
    config(['database.connections.tier_peer' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $peer = DB::connection('tier_peer');
    try {
        $peer->getSchemaBuilder()->create('subscription_tiers', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('slug');
            $table->string('name');
        });
        $peer->table('subscription_tiers')->insert(['id' => $primary->id, 'slug' => 'secondary_only', 'name' => 'Secondary']);
        $secondary = SubscriptionTier::on('tier_peer')->findOrFail($primary->id);
        $before = $peer->table('subscription_tiers')->get()->map(fn ($row) => (array) $row)->all();
        $failure = null;
        try {
            app(TierPermissionSyncService::class)->syncTierRolePermissions($secondary);
        } catch (LogicException $exception) {
            $failure = $exception;
        }
        expect(Role::query()->count())->toBe(0)
            ->and($failure)->toBeInstanceOf(LogicException::class)
            ->and($peer->table('subscription_tiers')->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
            ->and($primary->fresh()->slug)->toBe('primary_only');
    } finally {
        DB::purge('tier_peer');
    }
});
