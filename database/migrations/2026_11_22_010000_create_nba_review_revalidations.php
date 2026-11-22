<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nba_review_revalidations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_work_item_id')->constrained('analysis_work_items');
            $table->foreignId('prediction_review_revision_id')->constrained('prediction_review_revisions');
            $table->string('desired_fingerprint', 64);
            $table->string('material_hash', 64);
            $table->string('policy_version', 64);
            $table->json('evidence');
            $table->timestamp('created_at');
            $table->unique(['analysis_work_item_id', 'desired_fingerprint', 'prediction_review_revision_id'], 'nba_review_revalidation_identity');
            $table->index(['analysis_work_item_id', 'material_hash'], 'nba_review_revalidation_material');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nba_review_revalidations');
    }
};
