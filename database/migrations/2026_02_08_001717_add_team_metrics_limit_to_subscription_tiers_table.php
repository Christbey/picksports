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
        Schema::table('subscription_tiers', function (Blueprint $table) {
            $table->unsignedInteger('team_metrics_limit')->nullable()->after('features');
        });

        // Set default values based on current tier slugs
        DB::table('subscription_tiers')->where('slug', 'free')->update(['team_metrics_limit' => 10]);
        DB::table('subscription_tiers')->where('slug', 'basic')->update(['team_metrics_limit' => 25]);
        DB::table('subscription_tiers')->where('slug', 'pro')->update(['team_metrics_limit' => 50]);
        DB::table('subscription_tiers')->where('slug', 'premium')->update(['team_metrics_limit' => null]); // unlimited
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_tiers', function (Blueprint $table) {
            $table->dropColumn('team_metrics_limit');
        });
    }
};
