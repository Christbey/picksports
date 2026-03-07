<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_onboarding_progress');

        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->where('payload', 'like', '%App\\\\Notifications\\\\Onboarding\\\\%')
                ->delete();
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')
                ->where('payload', 'like', '%App\\\\Notifications\\\\Onboarding\\\\%')
                ->delete();
        }
    }

    public function down(): void
    {
        // Onboarding has been removed intentionally; no restore path.
    }
};
