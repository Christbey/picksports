<?php

use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('installs the rollout tables with lineage constraints and dispatch indexes in disposable SQLite', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');

    foreach (['analysis_work_items', 'prediction_review_revisions', 'research_sources', 'research_documents', 'game_research_assessments'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    $workIndexes = collect(Schema::getIndexes('analysis_work_items'))->keyBy('name');
    expect($workIndexes['analysis_work_due']['columns'])->toBe(['state', 'next_attempt_at', 'deadline_at'])
        ->and($workIndexes['analysis_work_sport_state']['columns'])->toBe(['sport', 'stage', 'state'])
        ->and(Schema::hasColumn('analysis_work_items', 'last_dispatched_at'))->toBeTrue();
    $documents = collect(Schema::getIndexes('research_documents'))->keyBy('name');
    expect($documents['research_document_content_unique']['unique'])->toBeTrue()
        ->and($documents['research_document_content_unique']['columns'])->toBe(['source_id', 'url_hash', 'content_hash', 'scope_hash', 'claims_hash']);

    $reviewParents = collect(Schema::getForeignKeys('prediction_review_revisions'))->pluck('foreign_table')->sort()->values()->all();
    expect($reviewParents)->toBe(['ai_generations', 'analysis_work_items', 'predictions', 'sport_events']);
    $assessmentParents = collect(Schema::getForeignKeys('game_research_assessments'))->pluck('foreign_table')->sort()->values()->all();
    expect($assessmentParents)->toBe(['sport_events', 'sports_game_context_reports']);

    $lineage = collect(Schema::getColumns('prediction_review_revisions'))->keyBy('name');
    expect($lineage['canonical_prediction_id']['nullable'])->toBeTrue()
        ->and($lineage['ai_generation_id']['nullable'])->toBeTrue()
        ->and($lineage['prediction_table']['nullable'])->toBeFalse()
        ->and($lineage['prediction_id']['nullable'])->toBeFalse();
});

it('recreates only shared rollout tables without altering NFL evidence or admission settings', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $report = SportsGameContextReport::create([
        'sport' => 'nfl', 'game_id' => $game->id, 'game_date' => $game->game_date,
        'status' => 'partial', 'provider' => 'stored', 'model' => null,
        'prompt_version' => 'rollout-preservation-fixture', 'input_hash' => hash('sha256', 'old-nfl-report'),
        'confidence' => 0, 'summary' => 'Existing NFL evidence.', 'researched_at' => now(), 'expires_at' => now()->addHour(),
    ]);
    $revision = ResearchRevision::create([
        'game_id' => $game->id, 'report_id' => $report->id, 'input_hash' => hash('sha256', 'old-nfl-revision'),
        'baseline' => ['predicted_spread' => 3], 'revised' => ['predicted_spread' => 3],
        'evidence' => [], 'brief' => ['eligibility' => ['status' => 'hold']], 'market' => [], 'created_at' => now(),
    ]);
    $nflSchema = Schema::getColumns('nfl_research_revisions');
    $gates = collect(['nfl_research.enabled', 'nfl_research.web_search_enabled', 'prediction_lifecycle.canonical_reads.nfl', 'prediction_lifecycle.canonical_pipeline.nfl'])
        ->mapWithKeys(fn (string $key): array => [$key => config($key)])->all();
    $reviewMigration = require database_path('migrations/2026_11_20_230100_create_prediction_review_work_tables.php');
    $researchMigration = require database_path('migrations/2026_11_21_010000_create_shared_research_storage.php');

    // Destructive reversal is exercised only in this disposable test database.
    // Operational production rollback disables admission and retains these tables.
    $researchMigration->down();
    $reviewMigration->down();
    expect(Schema::hasTable('research_documents'))->toBeFalse()
        ->and(Schema::hasTable('analysis_work_items'))->toBeFalse();
    $reviewMigration->up();
    $researchMigration->up();

    expect(Schema::getColumns('nfl_research_revisions'))->toBe($nflSchema)
        ->and($report->fresh()->summary)->toBe('Existing NFL evidence.')
        ->and($revision->fresh()->brief)->toBe(['eligibility' => ['status' => 'hold']])
        ->and($revision->fresh()->report_id)->toBe($report->id);
    foreach ($gates as $key => $before) {
        expect(config($key))->toBe($before);
    }
});
