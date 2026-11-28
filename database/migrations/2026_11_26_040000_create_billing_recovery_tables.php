<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';
        $identifier = static function (Blueprint $table, string $name, bool $nullable = false) use ($mysql): void {
            $column = $table->string($name, 191)->nullable($nullable);
            if ($mysql) {
                $column->charset('ascii')->collation('ascii_bin');
            }
        };
        Schema::create('billing_customer_reconciliations', function (Blueprint $table) use ($identifier): void {
            $table->id();
            $identifier($table, 'account_id');
            $table->boolean('livemode');
            $identifier($table, 'customer_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('generation')->default(0);
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('lease_sequence')->default(0);
            $table->uuid('owner_token')->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_observed_at', 6)->nullable();
            $table->char('last_projection_hash', 64)->nullable();
            $table->boolean('cache_invalidation_pending')->default(false);
            $table->unsignedBigInteger('cache_generation')->default(0);
            $table->unsignedBigInteger('cache_lease_sequence')->default(0);
            $table->json('requested_targets')->nullable();
            $table->unsignedBigInteger('operator_request_id')->nullable();
            $table->unsignedBigInteger('operator_generation')->nullable();
            $table->string('failure_category', 100)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unique(['account_id', 'livemode', 'customer_id'], 'billing_customer_scope_unique');
            $table->index(['status', 'next_attempt_at'], 'billing_customer_pending_index');
        });
        Schema::create('billing_webhook_receipts', function (Blueprint $table) use ($identifier): void {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('billing_customer_reconciliations')->restrictOnDelete();
            $identifier($table, 'account_id');
            $table->boolean('livemode');
            $identifier($table, 'event_id');
            $table->char('payload_hash', 64);
            $table->string('event_type', 100);
            $table->unsignedBigInteger('provider_created_at');
            $table->dateTime('received_at', 6);
            $identifier($table, 'customer_id');
            $identifier($table, 'subscription_id', true);
            $table->unsignedBigInteger('generation');
            $table->string('status', 32);
            $table->dateTime('processed_at', 6)->nullable();
            $table->string('failure_category', 100)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unique(['account_id', 'livemode', 'event_id'], 'billing_event_scope_unique');
            $table->index(['reconciliation_id', 'generation'], 'billing_receipt_generation_index');
        });
        Schema::create('billing_reconciliation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('billing_customer_reconciliations')->restrictOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('billing_webhook_receipts')->restrictOnDelete();
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('lease_sequence')->default(0);
            $table->uuid('owner_token');
            $table->string('kind', 32);
            $table->string('status', 32);
            $table->dateTime('started_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('fetch_started_at', 6)->nullable();
            $table->dateTime('fetch_completed_at', 6)->nullable();
            $table->char('source_fingerprint', 64)->nullable();
            $table->string('failure_category', 100)->nullable();
            $table->json('targets')->nullable();
            $table->json('before_projection')->nullable();
            $table->json('after_projection')->nullable();
            $table->json('observation_evidence')->nullable();
            $table->json('details')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['reconciliation_id', 'id'], 'billing_attempt_history_index');
        });
        Schema::table('billing_customer_reconciliations', function (Blueprint $table): void {
            $table->foreign('operator_request_id', 'billing_operator_request_fk')
                ->references('id')->on('billing_reconciliation_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve billing admission and reconciliation history; roll back application code without dropping these additive tables.');
    }
};
