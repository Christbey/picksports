<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_predictions', function (Blueprint $table): void {
            if (! Schema::hasColumn('mlb_predictions', 'vegas_spread')) {
                $table->decimal('vegas_spread', 5, 2)->nullable()->after('confidence_score');
            }

            if (! Schema::hasColumn('mlb_predictions', 'model_metadata')) {
                $table->json('model_metadata')->nullable()->after('blend_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mlb_predictions', function (Blueprint $table): void {
            $drops = [];

            if (Schema::hasColumn('mlb_predictions', 'vegas_spread')) {
                $drops[] = 'vegas_spread';
            }

            if (Schema::hasColumn('mlb_predictions', 'model_metadata')) {
                $drops[] = 'model_metadata';
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
