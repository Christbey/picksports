<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('sport', 16);
            $table->string('source_key', 128);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('kind', 32);
            $table->text('url');
            $table->json('allowed_hosts');
            $table->boolean('enabled')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->string('last_error', 128)->nullable();
            $table->timestamps();
            $table->unique(['sport', 'source_key']);
            $table->index(['sport', 'team_id', 'enabled']);
        });
        Schema::create('research_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained('research_sources');
            $table->string('sport', 16);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->foreignId('sport_event_id')->nullable()->constrained('sport_events');
            $table->text('url');
            $table->string('url_hash', 64);
            $table->string('content_hash', 64);
            $table->string('scope_hash', 64);
            $table->string('claims_hash', 64);
            $table->longText('body');
            $table->json('claims');
            $table->string('parser_version', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['source_id', 'url_hash', 'content_hash', 'scope_hash', 'claims_hash'], 'research_document_content_unique');
            $table->index(['sport', 'team_id', 'observed_at']);
            $table->index(['sport_event_id', 'observed_at']);
        });
        Schema::create('game_research_assessments', function (Blueprint $table): void {
            $table->id();
            $table->string('sport', 16);
            $table->unsignedBigInteger('game_id');
            $table->foreignId('sport_event_id')->nullable()->constrained('sport_events');
            $table->foreignId('report_id')->nullable()->constrained('sports_game_context_reports');
            $table->unsignedBigInteger('legacy_nfl_revision_id')->nullable();
            $table->string('evidence_hash', 64);
            $table->string('assessment_hash', 64);
            $table->string('policy_version', 64);
            $table->json('assessment');
            $table->timestamp('assessed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['sport', 'game_id', 'assessment_hash'], 'game_research_revision_unique');
            $table->index('evidence_hash');
            $table->index(['sport', 'game_id', 'assessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_research_assessments');
        Schema::dropIfExists('research_documents');
        Schema::dropIfExists('research_sources');
    }
};
