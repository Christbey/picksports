<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wnba_predictions')) {
            return;
        }

        Schema::table('wnba_predictions', function (Blueprint $table): void {
            if (! Schema::hasColumn('wnba_predictions', 'model_metadata')) {
                $table->json('model_metadata')->nullable()->after('blend_version');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wnba_predictions')) {
            return;
        }

        Schema::table('wnba_predictions', function (Blueprint $table): void {
            if (Schema::hasColumn('wnba_predictions', 'model_metadata')) {
                $table->dropColumn('model_metadata');
            }
        });
    }
};
