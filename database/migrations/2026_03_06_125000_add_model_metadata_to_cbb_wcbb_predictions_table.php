<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cbb_predictions', 'wcbb_predictions'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'model_metadata')) {
                    $table->json('model_metadata')->nullable()->after('confidence_score');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['cbb_predictions', 'wcbb_predictions'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'model_metadata')) {
                    $table->dropColumn('model_metadata');
                }
            });
        }
    }
};
