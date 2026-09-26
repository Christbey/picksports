<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_game_data_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_id')->constrained('cfb_games')->cascadeOnDelete();
            $t->string('component', 24);
            $t->string('source_hash', 64);
            $t->string('validator_version', 32);
            $t->string('state', 24);
            $t->json('discrepancies');
            $t->json('evidence');
            $t->string('source_path');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->unique(['game_id', 'component', 'source_hash', 'validator_version'], 'cfb_check_revision');
            $t->index(['game_id', 'component', 'accepted_at']);
        });
        Schema::create('cfb_pipeline_steps', function (Blueprint $t) {
            $t->id();
            $t->string('scope');
            $t->string('stage', 48);
            $t->string('input_hash', 64);
            $t->string('version', 24);
            $t->string('state', 24);
            $t->unsignedInteger('attempts')->default(0);
            $t->json('result')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['scope', 'stage', 'input_hash', 'version'], 'cfb_stage_revision');
        });
        Schema::table('cfb_team_stats', fn (Blueprint $t) => $t->json('team_attributed_stats')->nullable());
        Schema::table('cfb_plays', function (Blueprint $t) {
            $t->string('source_revision', 64)->nullable();
            $t->json('source_state')->nullable();
        });
        Schema::create('cfb_drives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('game_id')->constrained('cfb_games')->cascadeOnDelete();
            $t->string('source_revision', 64);
            $t->string('drive_key');
            $t->foreignId('offense_team_id')->nullable()->constrained('cfb_teams');
            $t->json('data');
            $t->string('quality', 24);
            $t->timestamps();
            $t->unique(['game_id', 'source_revision', 'drive_key'], 'cfb_drive_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_drives');
        Schema::table('cfb_plays', fn (Blueprint $t) => $t->dropColumn(['source_revision', 'source_state']));
        Schema::table('cfb_team_stats', fn (Blueprint $t) => $t->dropColumn('team_attributed_stats'));
        Schema::dropIfExists('cfb_pipeline_steps');
        Schema::dropIfExists('cfb_game_data_checks');
    }
};
