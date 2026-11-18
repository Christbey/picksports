<?php

use App\Models\NFL\ResearchSource;
use App\Services\NFL\Research\OfficialSourceIngestor;
use Illuminate\Support\Facades\Log;

it('records source and exception location without exposing source bodies or credentials and continues the batch', function () {
    config(['nfl_research.teams' => ['CIN' => 'www.bengals.com']]);
    Log::spy();
    $error = new RuntimeException('Rejected https://example.test/?token=private-secret with secret body');
    $ingestor = Mockery::mock(OfficialSourceIngestor::class)->makePartial();
    $ingestor->shouldReceive('poll')->times(4)->andReturnUsing(function (ResearchSource $source) use ($error): array {
        if ($source->kind === 'newsroom') {
            throw $error;
        }

        return ['changed' => 0];
    });

    $results = $ingestor->sync(['CIN']);
    $source = ResearchSource::query()->where('key', 'CIN:newsroom')->firstOrFail();

    expect($results['CIN:newsroom'])->toBe(['error' => 'RuntimeException'])
        ->and($source->error)->toBe('RuntimeException')
        ->and($source->checked_at)->not->toBeNull()
        ->and($results)->toHaveCount(4)
        ->and($results['CIN:roster'])->toBe(['changed' => 0]);
    Log::shouldHaveReceived('warning')->once()->with('NFL research source ingestion failed.', Mockery::on(function (array $context) use ($source, $error): bool {
        expect(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('private-secret', 'secret body', 'https://');

        return $context['source_id'] === $source->id
            && $context['source_key'] === 'CIN:newsroom'
            && $context['source_kind'] === 'newsroom'
            && $context['exception_class'] === RuntimeException::class
            && $context['exception_file'] === 'tests/Feature/NFL/ResearchSourceFailureDiagnosticsTest.php'
            && $context['exception_line'] === $error->getLine()
            && $context['error_fingerprint'] === hash('sha256', $error->getMessage());
    }));
});
