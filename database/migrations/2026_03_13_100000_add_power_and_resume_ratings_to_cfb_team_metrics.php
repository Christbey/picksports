<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_team_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('cfb_team_metrics', 'power_rating')) {
                $table->decimal('power_rating', 8, 3)->nullable()->after('cfp_rating');
            }

            if (! Schema::hasColumn('cfb_team_metrics', 'resume_rating')) {
                $table->decimal('resume_rating', 8, 3)->nullable()->after('power_rating');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cfb_team_metrics', function (Blueprint $table) {
            foreach (['resume_rating', 'power_rating'] as $column) {
                if (Schema::hasColumn('cfb_team_metrics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
