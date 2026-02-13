<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $statusMap = [
            'scheduled' => 'STATUS_SCHEDULED',
            'pre' => 'STATUS_SCHEDULED',
            'in progress' => 'STATUS_IN_PROGRESS',
            'in_progress' => 'STATUS_IN_PROGRESS',
            'in' => 'STATUS_IN_PROGRESS',
            'final' => 'STATUS_FINAL',
            'post' => 'STATUS_FINAL',
            'postponed' => 'STATUS_POSTPONED',
            'canceled' => 'STATUS_CANCELED',
            'cancelled' => 'STATUS_CANCELED',
            'suspended' => 'STATUS_SUSPENDED',
            'delayed' => 'STATUS_DELAYED',
        ];

        $tables = [
            'mlb_games',
            'nba_games',
            'nfl_games',
            'cbb_games',
            'cfb_games',
            'wcbb_games',
            'wnba_games',
        ];

        foreach ($tables as $table) {
            foreach ($statusMap as $oldStatus => $newStatus) {
                DB::table($table)
                    ->where('status', $oldStatus)
                    ->update(['status' => $newStatus]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse - the uppercase format is the correct one
    }
};
