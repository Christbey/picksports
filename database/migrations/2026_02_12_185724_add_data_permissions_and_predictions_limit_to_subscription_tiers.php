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
            $table->json('data_permissions')->nullable()->after('permissions');
            $table->integer('predictions_limit')->nullable()->after('data_permissions');
        });

        // Set default data permissions and limits for existing tiers
        DB::table('subscription_tiers')->where('slug', 'free')->update([
            'data_permissions' => json_encode(['spread']),
            'predictions_limit' => 5,
        ]);

        DB::table('subscription_tiers')->where('slug', 'basic')->update([
            'data_permissions' => json_encode(['spread', 'win_probability']),
            'predictions_limit' => 25,
        ]);

        DB::table('subscription_tiers')->where('slug', 'pro')->update([
            'data_permissions' => json_encode(['spread', 'win_probability', 'confidence_score', 'elo_diff']),
            'predictions_limit' => 100,
        ]);

        DB::table('subscription_tiers')->where('slug', 'premium')->update([
            'data_permissions' => json_encode([
                'spread',
                'win_probability',
                'confidence_score',
                'elo_diff',
                'away_elo',
                'home_elo',
                'betting_value',
            ]),
            'predictions_limit' => null, // unlimited
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_tiers', function (Blueprint $table) {
            $table->dropColumn(['data_permissions', 'predictions_limit']);
        });
    }
};
