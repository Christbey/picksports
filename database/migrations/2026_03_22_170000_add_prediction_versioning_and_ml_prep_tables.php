<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $predictionTables = [
        'nba_predictions',
        'cbb_predictions',
        'wcbb_predictions',
        'nfl_predictions',
        'cfb_predictions',
        'wnba_predictions',
        'mlb_predictions',
    ];

    public function up(): void
    {
        foreach ($this->predictionTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'model_version')) {
                    $table->string('model_version', 64)->nullable()->after('confidence_score');
                }

                if (! Schema::hasColumn($tableName, 'feature_version')) {
                    $table->string('feature_version', 64)->nullable()->after('model_version');
                }

                if (! Schema::hasColumn($tableName, 'blend_version')) {
                    $table->string('blend_version', 64)->nullable()->after('feature_version');
                }
            });
        }

        Schema::create('prediction_feature_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('prediction_table', 64);
            $table->unsignedBigInteger('prediction_id');
            $table->unsignedBigInteger('game_id');
            $table->string('model_version', 64);
            $table->string('feature_version', 64);
            $table->string('blend_version', 64);
            $table->json('features');
            $table->json('outputs');
            $table->json('market_context')->nullable();
            $table->json('model_metadata')->nullable();
            $table->string('feature_hash', 64)->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['prediction_table', 'prediction_id', 'model_version', 'feature_version', 'blend_version'],
                'prediction_feature_snapshots_unique'
            );
            $table->index(['sport', 'game_id']);
        });

        Schema::create('prediction_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('prediction_table', 64);
            $table->unsignedBigInteger('prediction_id');
            $table->unsignedBigInteger('game_id');
            $table->string('model_version', 64)->nullable();
            $table->string('feature_version', 64)->nullable();
            $table->string('blend_version', 64)->nullable();
            $table->json('actuals');
            $table->json('errors');
            $table->json('market_comparison')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(
                ['prediction_table', 'prediction_id', 'model_version', 'feature_version', 'blend_version'],
                'prediction_evaluations_unique'
            );
            $table->index(['sport', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_evaluations');
        Schema::dropIfExists('prediction_feature_snapshots');

        foreach ($this->predictionTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $drops = [];

                foreach (['model_version', 'feature_version', 'blend_version'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $drops[] = $column;
                    }
                }

                if ($drops !== []) {
                    $table->dropColumn($drops);
                }
            });
        }
    }
};
