<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_organizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('developer_organization_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('developer_organization_id')
                ->constrained(indexName: 'developer_membership_org_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('developer');
            $table->timestamps();
            $table->unique(
                ['developer_organization_id', 'user_id'],
                'developer_org_membership_user_unique',
            );
            $table->index(['user_id', 'role'], 'developer_org_membership_user_role_idx');
        });

        Schema::create('developer_products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('default_scopes')->nullable();
            $table->json('default_limits')->nullable();
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('developer_entitlement_policies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('developer_organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('developer_product_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->json('scopes')->nullable();
            $table->json('limits')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(
                ['developer_organization_id', 'developer_product_id', 'status'],
                'developer_entitlement_org_product_status_idx',
            );
            $table->index(['starts_at', 'ends_at'], 'developer_entitlement_window_idx');
        });

        Schema::create('developer_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('developer_organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('prefix', 16)->unique();
            $table->char('secret_hash', 64);
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(
                ['developer_organization_id', 'revoked_at'],
                'developer_api_credentials_org_revoked_idx',
            );
        });

        Schema::create('developer_api_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('developer_organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('developer_api_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('developer_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('developer_entitlement_policy_id')
                ->nullable()
                ->constrained(indexName: 'developer_usage_entitlement_fk')
                ->nullOnDelete();
            $table->string('request_id', 128);
            $table->string('operation', 120);
            $table->string('scope', 120)->nullable();
            $table->unsignedInteger('units')->default(1);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(
                ['developer_organization_id', 'occurred_at'],
                'developer_usage_org_occurred_idx',
            );
            $table->index(
                ['developer_api_credential_id', 'occurred_at'],
                'developer_usage_credential_occurred_idx',
            );
            $table->index(['request_id', 'operation'], 'developer_usage_request_operation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_api_usage_records');
        Schema::dropIfExists('developer_api_credentials');
        Schema::dropIfExists('developer_entitlement_policies');
        Schema::dropIfExists('developer_products');
        Schema::dropIfExists('developer_organization_memberships');
        Schema::dropIfExists('developer_organizations');
    }
};
