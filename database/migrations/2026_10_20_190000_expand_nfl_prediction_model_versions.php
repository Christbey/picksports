<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['nfl_predictions', 'prediction_evaluations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('model_version', 128)->nullable()->change();
            });
        }

        foreach (['prediction_feature_snapshots', 'nfl_signal_observations', 'model_runs', 'model_artifacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('model_version', 128)->change();
            });
        }
    }

    public function down(): void
    {
        // Retain capacity for version identifiers already written after this repair.
    }
};
