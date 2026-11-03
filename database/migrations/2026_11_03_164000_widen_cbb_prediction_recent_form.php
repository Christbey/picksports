<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_predictions', function (Blueprint $table): void {
            $table->decimal('home_recent_form', 7, 3)->nullable()->change();
            $table->decimal('away_recent_form', 7, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('cbb_predictions')->where(function ($query): void {
            foreach (['home_recent_form', 'away_recent_form'] as $column) {
                $query->orWhere($column, '>', 99.999)->orWhere($column, '<', -99.999);
            }
        })->exists()) {
            throw new RuntimeException('Cannot narrow CBB recent-form columns while larger values are stored.');
        }

        Schema::table('cbb_predictions', function (Blueprint $table): void {
            $table->decimal('home_recent_form', 5, 3)->nullable()->change();
            $table->decimal('away_recent_form', 5, 3)->nullable()->change();
        });
    }
};
