<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_template_defaults')
            ->where('alert_type', 'daily_betting_digest')
            ->delete();

        DB::table('notification_templates')
            ->where('name', 'Daily Betting Digest')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally left empty: deleted template/default rows are not restored.
    }
};
