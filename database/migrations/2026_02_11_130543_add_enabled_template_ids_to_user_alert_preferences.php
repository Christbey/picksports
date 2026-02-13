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
        Schema::table('user_alert_preferences', function (Blueprint $table) {
            $table->json('enabled_template_ids')->nullable()->after('notification_types');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_alert_preferences', function (Blueprint $table) {
            $table->dropColumn('enabled_template_ids');
        });
    }
};
