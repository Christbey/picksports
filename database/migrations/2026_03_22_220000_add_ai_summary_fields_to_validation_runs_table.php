<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('validation_runs', 'ai_summary')) {
                $table->json('ai_summary')->nullable()->after('summary');
            }

            if (! Schema::hasColumn('validation_runs', 'ai_provider')) {
                $table->string('ai_provider', 32)->nullable()->after('ai_summary');
            }

            if (! Schema::hasColumn('validation_runs', 'ai_model')) {
                $table->string('ai_model', 64)->nullable()->after('ai_provider');
            }

            if (! Schema::hasColumn('validation_runs', 'ai_generated_at')) {
                $table->timestamp('ai_generated_at')->nullable()->after('ai_model');
            }
        });
    }

    public function down(): void
    {
        Schema::table('validation_runs', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('validation_runs', 'ai_summary') ? 'ai_summary' : null,
                Schema::hasColumn('validation_runs', 'ai_provider') ? 'ai_provider' : null,
                Schema::hasColumn('validation_runs', 'ai_model') ? 'ai_model' : null,
                Schema::hasColumn('validation_runs', 'ai_generated_at') ? 'ai_generated_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
