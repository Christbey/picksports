<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nfl_player_stats')) {
            return;
        }

        Schema::table('nfl_player_stats', function (Blueprint $table) {
            if (! Schema::hasColumn('nfl_player_stats', 'passing_long')) {
                $table->integer('passing_long')->nullable()->after('sacks_taken');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'sack_yards_lost')) {
                $table->integer('sack_yards_lost')->nullable()->after('passing_long');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'passing_two_point_conversions')) {
                $table->integer('passing_two_point_conversions')->nullable()->after('sack_yards_lost');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'rushing_two_point_conversions')) {
                $table->integer('rushing_two_point_conversions')->nullable()->after('rushing_long');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'receiving_two_point_conversions')) {
                $table->integer('receiving_two_point_conversions')->nullable()->after('receiving_long');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'kickoff_returns')) {
                $table->integer('kickoff_returns')->nullable()->after('receiving_two_point_conversions');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'kickoff_return_yards')) {
                $table->integer('kickoff_return_yards')->nullable()->after('kickoff_returns');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'kickoff_return_touchdowns')) {
                $table->integer('kickoff_return_touchdowns')->nullable()->after('kickoff_return_yards');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'kickoff_return_long')) {
                $table->integer('kickoff_return_long')->nullable()->after('kickoff_return_touchdowns');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'kickoff_return_fair_catches')) {
                $table->integer('kickoff_return_fair_catches')->nullable()->after('kickoff_return_long');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'punt_returns')) {
                $table->integer('punt_returns')->nullable()->after('kickoff_return_fair_catches');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'punt_return_yards')) {
                $table->integer('punt_return_yards')->nullable()->after('punt_returns');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'punt_return_touchdowns')) {
                $table->integer('punt_return_touchdowns')->nullable()->after('punt_return_yards');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'punt_return_long')) {
                $table->integer('punt_return_long')->nullable()->after('punt_return_touchdowns');
            }
            if (! Schema::hasColumn('nfl_player_stats', 'punt_return_fair_catches')) {
                $table->integer('punt_return_fair_catches')->nullable()->after('punt_return_long');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nfl_player_stats')) {
            return;
        }

        Schema::table('nfl_player_stats', function (Blueprint $table) {
            $drops = [];
            foreach ([
                'passing_long',
                'sack_yards_lost',
                'passing_two_point_conversions',
                'rushing_two_point_conversions',
                'receiving_two_point_conversions',
                'kickoff_returns',
                'kickoff_return_yards',
                'kickoff_return_touchdowns',
                'kickoff_return_long',
                'kickoff_return_fair_catches',
                'punt_returns',
                'punt_return_yards',
                'punt_return_touchdowns',
                'punt_return_long',
                'punt_return_fair_catches',
            ] as $column) {
                if (Schema::hasColumn('nfl_player_stats', $column)) {
                    $drops[] = $column;
                }
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
