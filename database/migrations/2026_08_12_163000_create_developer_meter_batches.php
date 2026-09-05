<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developer_meter_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('meter_code', 64);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status', 20)->default('pending');
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('usage_record_count')->default(0);
            $table->unsignedBigInteger('total_units')->default(0);
            $table->timestamp('generated_at');
            $table->timestamp('exported_at')->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['meter_code', 'period_start', 'period_end'], 'developer_meter_period_idx');
            $table->index(['status', 'generated_at'], 'developer_meter_status_generated_idx');
        });

        Schema::create('developer_meter_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('developer_meter_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('developer_organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('developer_product_id')->nullable()->constrained()->nullOnDelete();
            $table->char('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('usage_record_count');
            $table->unsignedBigInteger('units');
            $table->json('dimensions')->nullable();
            $table->timestamps();
            $table->unique(
                ['developer_meter_batch_id', 'developer_organization_id', 'developer_product_id'],
                'developer_meter_item_group_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('developer_meter_batch_items');
        Schema::dropIfExists('developer_meter_batches');
    }
};
