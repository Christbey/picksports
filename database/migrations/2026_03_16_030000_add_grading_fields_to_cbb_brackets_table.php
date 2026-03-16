<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_brackets', function (Blueprint $table): void {
            $table->unsignedInteger('points_earned')->default(0)->after('picks');
            $table->unsignedInteger('max_points_remaining')->default(0)->after('points_earned');
            $table->unsignedInteger('correct_picks')->default(0)->after('max_points_remaining');
            $table->unsignedInteger('incorrect_picks')->default(0)->after('correct_picks');
            $table->string('graded_through_round')->nullable()->after('incorrect_picks');
            $table->json('results')->nullable()->after('graded_through_round');

            $table->index(['season', 'points_earned']);
            $table->index(['season', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cbb_brackets', function (Blueprint $table): void {
            $table->dropIndex(['season', 'points_earned']);
            $table->dropIndex(['season', 'submitted_at']);
            $table->dropColumn([
                'points_earned',
                'max_points_remaining',
                'correct_picks',
                'incorrect_picks',
                'graded_through_round',
                'results',
            ]);
        });
    }
};
