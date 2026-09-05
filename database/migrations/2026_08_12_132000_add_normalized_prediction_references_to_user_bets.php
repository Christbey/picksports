<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bets', function (Blueprint $table): void {
            $table->string('prediction_sport', 16)->nullable()->after('prediction_type');
            $table->foreignId('sport_event_id')
                ->nullable()
                ->after('prediction_sport')
                ->constrained('sport_events')
                ->nullOnDelete();
            $table->index(
                ['prediction_sport', 'prediction_id'],
                'user_bets_prediction_sport_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_bets', function (Blueprint $table): void {
            $table->dropIndex('user_bets_prediction_sport_id_index');
            $table->dropConstrainedForeignId('sport_event_id');
            $table->dropColumn('prediction_sport');
        });
    }
};
