<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cbb_predictions', 'wcbb_predictions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->smallInteger('rest_days_home')->nullable()->change();
                $table->smallInteger('rest_days_away')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['cbb_predictions', 'wcbb_predictions'] as $tableName) {
            if (DB::table($tableName)->where(function ($query): void {
                $query->where('rest_days_home', '>', 127)
                    ->orWhere('rest_days_away', '>', 127)
                    ->orWhere('rest_days_home', '<', -128)
                    ->orWhere('rest_days_away', '<', -128);
            })->exists()) {
                throw new RuntimeException('Cannot narrow rest-day columns while offseason values are stored.');
            }
        }

        foreach (['cbb_predictions', 'wcbb_predictions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->tinyInteger('rest_days_home')->nullable()->change();
                $table->tinyInteger('rest_days_away')->nullable()->change();
            });
        }
    }
};
