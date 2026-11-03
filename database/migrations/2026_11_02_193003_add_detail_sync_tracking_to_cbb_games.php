<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_games', function (Blueprint $table): void {
            $table->timestamp('details_attempted_at')->nullable()->index();
            $table->timestamp('details_completed_at')->nullable();
            $table->string('details_status', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cbb_games', function (Blueprint $table): void {
            $table->dropIndex(['details_attempted_at']);
            $table->dropColumn(['details_attempted_at', 'details_completed_at', 'details_status']);
        });
    }
};
