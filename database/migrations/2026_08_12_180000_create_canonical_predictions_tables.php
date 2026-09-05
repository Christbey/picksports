<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('sport_event_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 16);
            $table->string('detail_source', 32);
            $table->string('detail_sport', 16);
            $table->unsignedBigInteger('detail_id');
            $table->string('status', 20)->default('active');
            $table->string('model_version', 100)->nullable();
            $table->string('feature_version', 100)->nullable();
            $table->string('blend_version', 100)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['detail_source', 'detail_sport', 'detail_id'],
                'predictions_detail_reference_unique',
            );
            $table->index(['sport_event_id', 'status'], 'predictions_event_status_idx');
            $table->index(['sport', 'generated_at'], 'predictions_sport_generated_idx');
        });

        Schema::create('prediction_markets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('prediction_id')->constrained()->cascadeOnDelete();
            $table->string('market_type', 24);
            $table->string('selection', 24);
            $table->decimal('projected_line', 12, 4)->nullable();
            $table->decimal('probability', 8, 6)->nullable();
            $table->decimal('confidence_score', 10, 4)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(
                ['prediction_id', 'market_type', 'selection'],
                'prediction_markets_prediction_market_selection_unique',
            );
            $table->index(['market_type', 'is_primary'], 'prediction_markets_type_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_markets');
        Schema::dropIfExists('predictions');
    }
};
