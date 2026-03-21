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
        if (! Schema::hasTable('nfl_predictions')) {
            return;
        }

        Schema::table('nfl_predictions', function (Blueprint $table) {
            if (! Schema::hasColumn('nfl_predictions', 'model_metadata')) {
                $table->json('model_metadata')->nullable()->after('confidence_score');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('nfl_predictions')) {
            return;
        }

        Schema::table('nfl_predictions', function (Blueprint $table) {
            if (Schema::hasColumn('nfl_predictions', 'model_metadata')) {
                $table->dropColumn('model_metadata');
            }
        });
    }
};
