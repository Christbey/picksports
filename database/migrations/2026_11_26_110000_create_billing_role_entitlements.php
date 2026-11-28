<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_role_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'billing_role_user_fk')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreign('role_id', 'billing_role_role_fk')->references('id')->on(config('permission.table_names.roles', 'roles'))->restrictOnDelete();
            $table->string('origin', 16);
            $table->foreignId('subscription_tier_id')->constrained('subscription_tiers', 'id', 'billing_role_tier_fk')->restrictOnDelete();
            $table->foreignId('billing_reconciliation_attempt_id')->nullable()->constrained('billing_reconciliation_attempts', 'id', 'billing_role_attempt_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id'], 'billing_role_user_role_unique');
        });
        Schema::create('billing_role_entitlement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'billing_grant_event_user_fk')->nullOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreign('role_id', 'billing_grant_event_role_fk')->references('id')->on(config('permission.table_names.roles', 'roles'))->restrictOnDelete();
            $table->foreignId('subscription_tier_id')->constrained('subscription_tiers', 'id', 'billing_grant_event_tier_fk')->restrictOnDelete();
            $table->foreignId('billing_reconciliation_attempt_id')->nullable()->constrained('billing_reconciliation_attempts', 'id', 'billing_grant_event_attempt_fk')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id', 'billing_grant_event_actor_fk')->nullOnDelete();
            $table->string('actor_origin', 32);
            $table->string('reason', 64);
            $table->json('before_grant')->nullable();
            $table->json('after_grant')->nullable();
            $table->dateTime('observed_at', 6);
            $table->index(['user_id', 'id'], 'billing_grant_event_user_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Entitlement provenance must be retained; rollback requires a separately reviewed retirement.');
    }
};
