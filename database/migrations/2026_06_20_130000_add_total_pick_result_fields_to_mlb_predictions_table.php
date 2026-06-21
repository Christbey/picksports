<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_predictions', function (Blueprint $table): void {
            if (! Schema::hasColumn('mlb_predictions', 'total_pick_side')) {
                $table->string('total_pick_side', 10)->nullable()->after('winner_correct');
            }

            if (! Schema::hasColumn('mlb_predictions', 'total_pick_line')) {
                $table->decimal('total_pick_line', 6, 2)->nullable()->after('total_pick_side');
            }

            if (! Schema::hasColumn('mlb_predictions', 'total_pick_result')) {
                $table->string('total_pick_result', 10)->nullable()->after('total_pick_line');
            }

            if (! Schema::hasColumn('mlb_predictions', 'total_pick_edge')) {
                $table->decimal('total_pick_edge', 6, 2)->nullable()->after('total_pick_result');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mlb_predictions', function (Blueprint $table): void {
            foreach ([
                'total_pick_side',
                'total_pick_line',
                'total_pick_result',
                'total_pick_edge',
            ] as $column) {
                if (Schema::hasColumn('mlb_predictions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
