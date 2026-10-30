<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->restrictOnDelete();
        });
        Schema::table('nfl_signal_observations', function (Blueprint $table): void {
            $table->unsignedBigInteger('prediction_id')->nullable()->change();
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('prediction_feature_snapshots')->whereNotNull('canonical_prediction_id')->exists()
            || DB::table('nfl_signal_observations')->whereNotNull('canonical_prediction_id')->exists()
            || DB::table('nfl_signal_observations')->whereNull('prediction_id')->exists()) {
            throw new LogicException('Preserve native NFL evidence lineage before rolling back this migration.');
        }
        Schema::table('nfl_signal_observations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canonical_prediction_id');
            $table->unsignedBigInteger('prediction_id')->nullable(false)->change();
        });
        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('canonical_prediction_id');
        });
    }
};
