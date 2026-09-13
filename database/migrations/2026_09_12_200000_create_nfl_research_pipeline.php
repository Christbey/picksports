<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_research_sources', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('team');
            $t->text('url');
            $t->string('kind');
            $t->string('etag')->nullable();
            $t->string('last_modified')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('succeeded_at')->nullable();
            $t->timestamp('latest_published_at')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('nfl_research_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_id')->constrained('nfl_research_sources');
            $t->string('team')->index();
            $t->text('url');
            $t->string('url_hash', 64);
            $t->string('content_hash', 64);
            $t->text('title');
            $t->longText('body');
            $t->json('structured')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('observed_at');
            $t->unique(['url_hash', 'content_hash']);
            $t->index(['team', 'observed_at']);
        });
        Schema::create('nfl_research_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_id')->constrained('nfl_games');
            $t->unsignedBigInteger('report_id')->nullable();
            $t->string('input_hash', 64);
            $t->json('baseline');
            $t->json('revised');
            $t->json('evidence');
            $t->json('brief');
            $t->json('market');
            $t->json('evaluation')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('graded_at')->nullable();
            $t->unique(['game_id', 'input_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_research_revisions');
        Schema::dropIfExists('nfl_research_documents');
        Schema::dropIfExists('nfl_research_sources');
    }
};
