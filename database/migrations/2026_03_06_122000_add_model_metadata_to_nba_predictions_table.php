<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nba_predictions')) {
            return;
        }

        Schema::table('nba_predictions', function (Blueprint $table) {
            if (! Schema::hasColumn('nba_predictions', 'model_metadata')) {
                $table->json('model_metadata')->nullable()->after('confidence_score');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nba_predictions')) {
            return;
        }

        Schema::table('nba_predictions', function (Blueprint $table) {
            if (Schema::hasColumn('nba_predictions', 'model_metadata')) {
                $table->dropColumn('model_metadata');
            }
        });
    }
};
