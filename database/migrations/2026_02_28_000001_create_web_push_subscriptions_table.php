<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint')->unique();
            $table->text('public_key');
            $table->string('auth_token');
            $table->string('content_encoding')->default('aes128gcm');
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};
