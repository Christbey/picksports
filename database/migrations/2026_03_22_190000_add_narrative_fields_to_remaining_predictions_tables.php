<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'nfl_predictions',
        'cbb_predictions',
        'mlb_predictions',
        'cfb_predictions',
        'wnba_predictions',
        'wcbb_predictions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->json('narrative_json')->nullable()->after('live_updated_at');
                $table->string('narrative_provider', 32)->nullable()->after('narrative_json');
                $table->string('narrative_model', 64)->nullable()->after('narrative_provider');
                $table->string('narrative_input_hash', 64)->nullable()->after('narrative_model');
                $table->unsignedInteger('narrative_latency_ms')->nullable()->after('narrative_input_hash');
                $table->timestamp('narrative_generated_at')->nullable()->after('narrative_latency_ms');

                $table->index('narrative_input_hash');
                $table->index('narrative_generated_at');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['narrative_input_hash']);
                $table->dropIndex(['narrative_generated_at']);
                $table->dropColumn([
                    'narrative_json',
                    'narrative_provider',
                    'narrative_model',
                    'narrative_input_hash',
                    'narrative_latency_ms',
                    'narrative_generated_at',
                ]);
            });
        }
    }
};
