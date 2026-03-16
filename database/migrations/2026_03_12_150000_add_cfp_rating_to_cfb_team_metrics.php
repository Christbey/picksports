<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_team_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('cfb_team_metrics', 'cfp_rating')) {
                $table->decimal('cfp_rating', 8, 3)->nullable()->after('cfbd_wepa_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cfb_team_metrics', function (Blueprint $table) {
            if (Schema::hasColumn('cfb_team_metrics', 'cfp_rating')) {
                $table->dropColumn('cfp_rating');
            }
        });
    }
};
