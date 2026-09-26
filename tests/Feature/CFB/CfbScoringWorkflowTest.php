<?php

use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\CFB\Data\CfbPipelineStage;
use App\Services\CFB\Scoring\CfbScoringArtifact;
use App\Services\CFB\Scoring\CfbScoringChallenger;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('retries failed stages, reuses success, and invalidates changed inputs', function () {
    $stage = app(CfbPipelineStage::class);
    $attempts = 0;
    $work = function () use (&$attempts) {
        $attempts++;

        return ['state' => $attempts === 1 ? 'failed' : 'complete'];
    };
    expect($stage->run('game:1', 'forecast', ['hash' => 'a'], $work)['state'])->toBe('failed');
    expect($stage->run('game:1', 'forecast', ['hash' => 'a'], $work)['state'])->toBe('complete');
    $stage->run('game:1', 'forecast', ['hash' => 'a'], $work);
    expect($attempts)->toBe(2);
    $stage->run('game:1', 'forecast', ['hash' => 'b'], $work);
    expect($attempts)->toBe(3);
});

it('keeps the released forecast while shadowing and adds coherent markets only after promotion', function () {
    $baseline = new PredictionOutput([new PredictionMarketOutput('spread', 'home', projectedLine: -7)], ['input_quality' => ['risk_flags' => []]]);
    $frozen = ['state' => 'ready', 'promoted' => false, 'summary' => ['home_margin' => 21.0, 'total' => 49.0, 'home_points' => 35.0, 'away_points' => 14.0, 'home_win_probability' => .8]];
    $service = app(CfbScoringChallenger::class);
    expect($service->apply($baseline, $frozen)->markets[0]->projectedLine)->toBe(-7.0);
    $frozen['promoted'] = true;
    $output = $service->apply($baseline, $frozen);
    expect(collect($output->markets)->where('marketType', 'team_total')->count())->toBe(2)
        ->and($output->metadata['input_quality']['risk_flags'])->toContain('challenger_market_validation_required');
});

it('verifies artifact content and prevents retrospective research feeding live forecasts', function () {
    Storage::fake('local');
    $json = file_get_contents(__DIR__.'/../../Fixtures/cfb-scoring-synthetic.json');
    Storage::disk('local')->put('model.json', $json);
    $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'cfb', 'run_type' => 'training', 'blend_version' => 'cfb-possession-v1', 'model_version' => 'test', 'feature_version' => 'test', 'config_hash' => hash('sha256', 'test'), 'code_version' => 'test', 'status' => 'completed', 'started_at' => now()]);
    $artifact = ModelArtifact::create(['training_run_id' => $run->id, 'sport' => 'cfb', 'market_type' => 'multi_market', 'model_type' => 'cfb_possession',
        'model_version' => 'test', 'feature_version' => 'test', 'status' => 'challenger', 'artifact_disk' => 'local', 'artifact_object_key' => 'model.json', 'artifact_path' => 'model.json', 'dataset_hash' => hash('sha256', 'fixture'), 'artifact_hash' => hash('sha256', $json)]);
    expect(app(CfbScoringArtifact::class)->load($artifact->id, now())['payload']['schema'])->toBe('cfb-possession-v1');
    Storage::disk('local')->put('model.json', '{}');
    expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))->toThrow(DomainException::class, 'integrity');
    $payload = json_decode($json, true);
    $payload['evidence_mode'] = 'retrospective';
    $json = json_encode($payload);
    Storage::disk('local')->put('model.json', $json);
    $artifact->update(['artifact_hash' => hash('sha256', $json)]);
    expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now()))->toThrow(DomainException::class, 'Retrospective');
    expect(app(CfbScoringArtifact::class)->load($artifact->id, now(), true)['payload']['evidence_mode'])->toBe('retrospective');
    $this->artisan('cfb:promote-scoring-challenger', ['--artifact' => $artifact->id])->assertExitCode(2);
    expect($artifact->fresh()->status)->toBe('challenger');
    $payload['max_source_available_at'] = now()->addDay()->toIso8601String();
    $json = json_encode($payload);
    Storage::disk('local')->put('model.json', $json);
    $artifact->update(['artifact_hash' => hash('sha256', $json)]);
    expect(fn () => app(CfbScoringArtifact::class)->load($artifact->id, now(), true))->toThrow(DomainException::class);

});
