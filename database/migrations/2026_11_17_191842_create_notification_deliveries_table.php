<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('recipient_key', 191);
            $table->string('channel');
            $table->string('deduplication_key', 191)->unique();
            $table->string('status')->default('planned')->index();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('reason_code')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
