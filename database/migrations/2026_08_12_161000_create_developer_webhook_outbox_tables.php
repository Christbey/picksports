<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('developer_organization_id')
                ->constrained(indexName: 'developer_webhook_endpoint_org_fk')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('signing_secret');
            $table->json('events');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
            $table->index(['developer_organization_id', 'status'], 'developer_webhook_endpoint_org_status_idx');
        });

        Schema::create('developer_webhook_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('developer_organization_id')
                ->constrained(indexName: 'developer_webhook_event_org_fk')
                ->cascadeOnDelete();
            $table->string('event_id', 120);
            $table->string('event_type', 120);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['developer_organization_id', 'event_id'], 'developer_webhook_outbox_org_event_unique');
            $table->index(['developer_organization_id', 'occurred_at'], 'developer_webhook_outbox_org_occurred_idx');
        });

        Schema::create('developer_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('developer_webhook_outbox_event_id')
                ->constrained('developer_webhook_outbox_events', indexName: 'developer_webhook_delivery_event_fk')
                ->cascadeOnDelete();
            $table->foreignId('developer_webhook_endpoint_id')
                ->constrained('developer_webhook_endpoints', indexName: 'developer_webhook_delivery_endpoint_fk')
                ->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(
                ['developer_webhook_outbox_event_id', 'developer_webhook_endpoint_id'],
                'developer_webhook_delivery_event_endpoint_unique',
            );
            $table->index(['status', 'available_at'], 'developer_webhook_delivery_ready_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_webhook_deliveries');
        Schema::dropIfExists('developer_webhook_outbox_events');
        Schema::dropIfExists('developer_webhook_endpoints');
    }
};
