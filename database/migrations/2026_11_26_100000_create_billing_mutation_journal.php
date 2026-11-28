<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';
        $identifier = static function (Blueprint $table, string $name) use ($mysql): void {
            $column = $table->string($name, 191)->nullable();
            if ($mysql) {
                $column->charset('ascii')->collation('ascii_bin');
            }
        };
        $dates = static function (Blueprint $table): void {
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        };
        Schema::create('billing_mutation_intents', function (Blueprint $table) use ($identifier, $dates): void {
            $table->id();
            $table->uuid('operation_uuid');
            $table->unique('operation_uuid', 'bill_mi_uuid_uq');
            $table->unsignedBigInteger('subject_user_id');
            $table->foreignId('user_id')->nullable();
            $table->index('user_id', 'bill_mi_user_idx');
            $table->foreign('user_id', 'bill_mi_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('kind', 40);
            $table->string('policy', 40);
            $identifier($table, 'account_id');
            $table->boolean('livemode')->nullable();
            $identifier($table, 'requested_customer_id');
            $identifier($table, 'requested_subscription_id');
            $identifier($table, 'desired_price_id');
            $table->char('request_key_hash', 64)->nullable();
            $table->char('request_hash', 64);
            $table->unique(['subject_user_id', 'request_key_hash'], 'bill_mi_request_uq');
            $table->json('facts')->nullable();
            $table->dateTime('requested_at', 6);
            $dates($table);
        });
        Schema::create('billing_mutation_states', function (Blueprint $table) use ($identifier, $dates): void {
            $table->id();
            $table->unsignedBigInteger('subject_user_id');
            $table->unique('subject_user_id', 'bill_ms_subject_uq');
            $table->foreignId('user_id')->nullable();
            $table->index('user_id', 'bill_ms_user_idx');
            $table->foreign('user_id', 'bill_ms_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('active_intent_id')->nullable();
            $table->unique('active_intent_id', 'bill_ms_active_uq');
            $table->foreign('active_intent_id', 'bill_ms_intent_fk')->references('id')->on('billing_mutation_intents')->restrictOnDelete();
            $identifier($table, 'customer_id');
            $table->foreignId('reconciliation_id')->nullable();
            $table->index('reconciliation_id', 'bill_ms_recon_idx');
            $table->foreign('reconciliation_id', 'bill_ms_recon_fk')->references('id')->on('billing_customer_reconciliations')->restrictOnDelete();
            $table->string('status', 40)->default('idle');
            $table->uuid('owner_token')->nullable();
            $table->unsignedBigInteger('lease_sequence')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('execution_started_at', 6)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->unsignedBigInteger('last_receipt_id')->nullable();
            $table->string('failure_category', 100)->nullable();
            $table->text('result')->nullable();
            $table->dateTime('result_expires_at', 6)->nullable();
            $dates($table);
            $table->index(['status', 'next_attempt_at'], 'bill_ms_due_idx');
        });
        Schema::create('billing_mutation_receipts', function (Blueprint $table) use ($dates): void {
            $table->id();
            $table->foreignId('state_id');
            $table->index('state_id', 'bill_mr_state_idx');
            $table->foreign('state_id', 'bill_mr_state_fk')->references('id')->on('billing_mutation_states')->restrictOnDelete();
            $table->foreignId('intent_id');
            $table->unsignedBigInteger('lease_sequence');
            $table->string('phase', 50);
            $table->unique(['intent_id', 'lease_sequence', 'phase'], 'bill_mr_phase_uq');
            $table->foreign('intent_id', 'bill_mr_intent_fk')->references('id')->on('billing_mutation_intents')->restrictOnDelete();
            $table->uuid('owner_token')->nullable();
            $table->char('provider_step_key', 64)->nullable();
            $table->unique('provider_step_key', 'bill_mr_send_uq');
            $table->unsignedBigInteger('cause_receipt_id')->nullable();
            $table->index('cause_receipt_id', 'bill_mr_cause_idx');
            $table->foreign('cause_receipt_id', 'bill_mr_cause_fk')->references('id')->on('billing_mutation_receipts')->restrictOnDelete();
            $table->char('payload_hash', 64)->nullable();
            $table->json('facts')->nullable();
            $table->dateTime('recorded_at', 6);
            $dates($table);
        });
        Schema::table('billing_mutation_states', function (Blueprint $table): void {
            $table->index('last_receipt_id', 'bill_ms_receipt_idx');
            $table->foreign('last_receipt_id', 'bill_ms_receipt_fk')->references('id')->on('billing_mutation_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Preserve billing intent, possible-send and outcome evidence; use a compatible forward repair.');
    }
};
