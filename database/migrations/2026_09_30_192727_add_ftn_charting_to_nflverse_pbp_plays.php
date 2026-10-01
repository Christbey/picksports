<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            foreach (['is_play_action', 'is_screen_pass', 'is_rpo', 'is_motion'] as $field) {
                $table->boolean('ftn_'.$field)->nullable();
            }
            foreach (['n_defense_box', 'n_blitzers', 'n_pass_rushers'] as $field) {
                $table->unsignedTinyInteger('ftn_'.$field)->nullable();
            }
            $table->timestamp('ftn_observed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->dropColumn(['ftn_is_play_action', 'ftn_is_screen_pass', 'ftn_is_rpo', 'ftn_is_motion',
                'ftn_n_defense_box', 'ftn_n_blitzers', 'ftn_n_pass_rushers', 'ftn_observed_at']);
        });
    }
};
