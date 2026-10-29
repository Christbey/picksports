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
        Schema::table('shadow_model_outputs', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_feature_snapshot_id')->nullable()->change();
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->restrictOnDelete();
            $table->unique(['model_artifact_id', 'canonical_prediction_id', 'market_type'], 'shadow_native_market_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('shadow_model_outputs')->whereNotNull('canonical_prediction_id')->exists()) {
            throw new LogicException('Preserve native shadow lineage before rolling back this migration.');
        }
        Schema::table('shadow_model_outputs', function (Blueprint $table) {
            $table->unsignedBigInteger('prediction_feature_snapshot_id')->nullable(false)->change();
            $table->dropUnique('shadow_native_market_unique');
            $table->dropConstrainedForeignId('canonical_prediction_id');
        });
    }
};
