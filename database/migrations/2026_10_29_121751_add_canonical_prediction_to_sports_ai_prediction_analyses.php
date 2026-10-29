<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sports_ai_prediction_analyses', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_id')->nullable()->change();
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->restrictOnDelete();
            $table->unique(['canonical_prediction_id', 'market', 'as_of_date'], 'sports_ai_native_unique_daily');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sports_ai_prediction_analyses')->whereNotNull('canonical_prediction_id')->exists()) {
            throw new LogicException('Preserve native AI analysis lineage before rolling back this migration.');
        }
        Schema::table('sports_ai_prediction_analyses', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_id')->nullable(false)->change();
            $table->dropUnique('sports_ai_native_unique_daily');
            $table->dropConstrainedForeignId('canonical_prediction_id');
        });
    }
};
