<?php

use App\Models\AnalysisWorkItem;
use App\Models\GameResearchAssessment;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerInjuryObservation;
use App\Models\NBA\PlayerInjurySnapshot;
use App\Models\NBA\PlayerInjurySnapshotEntry;
use App\Models\NBA\ResearchContextSnapshot;
use App\Models\NBA\ReviewRevalidation;
use App\Models\NBA\Team;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\GameDepthChartLink;
use App\Models\NFL\Prediction;
use App\Models\PredictionReviewRevision;
use App\Models\ResearchDocument;
use App\Models\ResearchSource;
use App\Models\SportEventResult;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

function appendOnlyEvidenceFixture(string $kind): Model
{
    if ($kind === 'result') {
        return SportEventResult::factory()->create();
    }
    if ($kind === 'assessment') {
        return GameResearchAssessment::create(['sport' => 'nba', 'game_id' => 1, 'evidence_hash' => hash('sha256', 'evidence'),
            'assessment_hash' => hash('sha256', 'assessment'), 'policy_version' => 'fixture', 'assessment' => [], 'assessed_at' => now()]);
    }
    if (in_array($kind, ['document', 'context'], true)) {
        $source = ResearchSource::create(['sport' => 'nba', 'source_key' => 'fixture', 'kind' => 'fixture',
            'url' => 'https://fixture.example.test/evidence', 'allowed_hosts' => ['fixture.example.test']]);
        if ($kind === 'document') {
            return ResearchDocument::create(['source_id' => $source->id, 'sport' => 'nba', 'url' => $source->url,
                'url_hash' => hash('sha256', 'url'), 'content_hash' => hash('sha256', 'body'), 'scope_hash' => hash('sha256', 'scope'),
                'claims_hash' => hash('sha256', 'claims'), 'body' => 'Frozen body', 'claims' => [], 'parser_version' => 'fixture', 'observed_at' => now()]);
        }

        return ResearchContextSnapshot::create(['game_id' => Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()])->id, 'source_id' => $source->id,
            'identity_hash' => hash('sha256', 'identity'), 'capture_hash' => hash('sha256', 'capture'), 'responses' => [], 'captured_at' => now()]);
    }
    if ($kind === 'revalidation') {
        $work = AnalysisWorkItem::create(['work_key' => hash('sha256', 'work'), 'sport' => 'nba', 'game_table' => 'nba_games',
            'game_id' => 1, 'desired_fingerprint' => hash('sha256', 'desired'), 'input_payload' => [], 'execution_spec' => [], 'evidence' => []]);
        $revision = PredictionReviewRevision::create(['analysis_work_item_id' => $work->id, 'sport' => 'nba', 'prediction_table' => 'nba_predictions',
            'prediction_id' => 1, 'desired_fingerprint' => $work->desired_fingerprint, 'input_hash' => hash('sha256', 'input'),
            'execution_hash' => hash('sha256', 'execution'), 'evidence_hash' => hash('sha256', 'evidence'), 'input_payload' => [],
            'execution_spec' => [], 'output' => [], 'decision' => [], 'expires_at' => now()->addHour(), 'created_at' => now()]);

        return ReviewRevalidation::create(['analysis_work_item_id' => $work->id, 'prediction_review_revision_id' => $revision->id,
            'desired_fingerprint' => $work->desired_fingerprint, 'material_hash' => hash('sha256', 'material'), 'policy_version' => 'fixture',
            'evidence' => [], 'created_at' => now()]);
    }
    if ($kind === 'depth_link') {
        $game = App\Models\NFL\Game::factory()->create(['home_team_id' => App\Models\NFL\Team::factory(), 'away_team_id' => App\Models\NFL\Team::factory()]);
        $prediction = Prediction::factory()->create(['game_id' => $game->id]);
        $feature = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', []);
        $snapshot = DepthChartSnapshot::create(['snapshot_uuid' => (string) Str::uuid(), 'team_id' => $game->home_team_id,
            'espn_team_id' => $game->homeTeam->espn_id, 'season' => $game->season, 'provider' => 'espn', 'observed_at' => now(),
            'payload_hash' => hash('sha256', 'depth'), 'entry_count' => 0]);

        return GameDepthChartLink::create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'side' => 'home',
            'snapshot_id' => $snapshot->id, 'prediction_feature_snapshot_id' => $feature->id, 'observed_at' => now(), 'as_of' => now(),
            'selected_at' => now(), 'source' => 'fixture', 'identity_status' => 'fixture', 'selection_mode' => 'fixture', 'evidence' => []]);
    }
    $team = Team::factory()->create();
    $snapshot = PlayerInjurySnapshot::create(['team_id' => $team->id, 'provider' => 'fixture', 'source_type' => 'fixture',
        'source_url' => 'https://fixture.example.test/injuries', 'normalizer_version' => 'fixture', 'snapshot_hash' => hash('sha256', 'snapshot'),
        'payload_hash' => hash('sha256', 'payload'), 'material_hash' => hash('sha256', 'material'), 'outcome' => 'complete',
        'entry_count' => 1, 'raw_payload' => [], 'issues' => [], 'created_at' => now()]);
    if ($kind === 'injury_snapshot') {
        return $snapshot;
    }
    if ($kind === 'injury_entry') {
        return PlayerInjurySnapshotEntry::create(['snapshot_id' => $snapshot->id, 'position' => 0, 'issues' => [], 'raw_payload' => [], 'created_at' => now()]);
    }

    return PlayerInjuryObservation::create(['observation_uuid' => (string) Str::uuid(), 'team_id' => $team->id, 'snapshot_id' => $snapshot->id,
        'outcome' => 'complete', 'started_at' => now(), 'completed_at' => now(), 'cache_hit' => false, 'fetch_metadata' => [], 'issues' => []]);
}

it('keeps append only evidence unchanged through ordinary quiet and numeric instance methods', function (string $kind, string $method) {
    $model = appendOnlyEvidenceFixture($kind)->refresh();
    $before = $model->getRawOriginal();
    $mutation = function () use ($model, $method): void {
        if (in_array($method, ['save', 'saveQuietly'], true)) {
            $model->id = $model->id + 1000;
            $model->{$method}();
        } elseif (in_array($method, ['delete', 'deleteQuietly'], true)) {
            $model->{$method}();
        } else {
            $model->{$method}('id');
        }
    };
    expect($mutation)->toThrow(LogicException::class);
    expect($model->newQuery()->findOrFail($before['id'])->getRawOriginal())->toBe($before);
})->with(function () {
    foreach (['result', 'assessment', 'document', 'context', 'revalidation', 'depth_link', 'injury_snapshot', 'injury_entry', 'injury_observation'] as $kind) {
        foreach (['save', 'delete', 'saveQuietly', 'deleteQuietly', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly'] as $method) {
            yield $kind.' '.$method => [$kind, $method];
        }
    }
});
