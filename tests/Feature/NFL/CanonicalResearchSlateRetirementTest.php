<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-09-16 12:00:00');
    Http::preventStrayRequests();
    config(['nfl_research.enabled' => true]);
});
afterEach(fn () => $this->travelBack());

function researchSlateRetirementGame(): Game
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => '2026-09-20', 'game_time' => '17:00:00', 'status' => 'STATUS_SCHEDULED',
        'season' => 2026, 'season_type' => '2']);
    Prediction::factory()->create(['game_id' => $game->id]);

    return $game;
}

function researchSlateRetirementQueries(Closure $run): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $exit = $run();

        return [$exit, collect(DB::getQueryLog())];
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('delegates canonical baseline selection without preloading legacy rows and retains missing baseline holds', function (bool $pipeline, bool $reads) {
    config(['prediction_lifecycle.canonical_pipeline.nfl' => $pipeline, 'prediction_lifecycle.canonical_reads.nfl' => $reads]);
    $game = researchSlateRetirementGame();
    $reader = new ResearchPipeline;
    $this->mock(ResearchPipeline::class)->shouldReceive('review')->once()->andReturnUsing(function (Game $selected, bool $web) use ($game, $reader) {
        expect($selected->id)->toBe($game->id)->and($selected->relationLoaded('prediction'))->toBeFalse()
            ->and($web)->toBeFalse()->and($reader->storedForecast($selected))->toBeNull();
        $revision = new ResearchRevision(['brief' => ['eligibility' => ['status' => 'hold', 'data_reasons' => ['original_prediction_missing']], 'change' => []]]);
        $revision->id = 1;

        return $revision;
    });
    [$exit, $queries] = researchSlateRetirementQueries(fn () => Artisan::call('nfl:research-pipeline', ['--no-ingest' => true, '--no-web' => true]));
    expect($exit)->toBe(1)->and($queries->pluck('query')->implode(' '))->not->toContain('nfl_predictions')
        ->and(Artisan::output())->toContain('original_prediction_missing')
        ->and(ResearchRevision::count())->toBe(0);
})->with([[true, false], [false, true], [true, true]]);

it('preloads historical predictions only for the selected compatibility batch', function () {
    config(['prediction_lifecycle.canonical_pipeline.nfl' => false, 'prediction_lifecycle.canonical_reads.nfl' => false]);
    $selected = researchSlateRetirementGame();
    researchSlateRetirementGame();
    $reader = new ResearchPipeline;
    $this->mock(ResearchPipeline::class)->shouldReceive('review')->once()->andReturnUsing(function (Game $game) use ($selected, $reader) {
        expect($game->id)->toBe($selected->id)->and($game->relationLoaded('prediction'))->toBeTrue()
            ->and($reader->storedForecast($game)['id'])->toBe($game->prediction->id);

        return null;
    });
    [$exit, $queries] = researchSlateRetirementQueries(fn () => Artisan::call('nfl:research-pipeline', ['--no-ingest' => true, '--no-web' => true, '--limit' => 1]));
    $predictionQueries = $queries->filter(fn ($q) => str_contains($q['query'], 'from "nfl_predictions"'));
    expect($exit)->toBe(1)->and($predictionQueries)->toHaveCount(1)
        ->and($predictionQueries->sole()['query'])->toContain('in ('.$selected->id.')');
});

it('does not load forecasts in ingest-only mode even in historical compatibility', function () {
    config(['prediction_lifecycle.canonical_pipeline.nfl' => false, 'prediction_lifecycle.canonical_reads.nfl' => false]);
    researchSlateRetirementGame();
    $this->mock(OfficialSourceIngestor::class)->shouldReceive('sync')->once()->andReturn([]);
    $this->mock(ResearchPipeline::class)->shouldNotReceive('review');
    [$exit, $queries] = researchSlateRetirementQueries(fn () => Artisan::call('nfl:research-pipeline', ['--ingest-only' => true]));
    expect($exit)->toBe(0)->and($queries->pluck('query')->implode(' '))->not->toContain('nfl_predictions')->not->toContain('from "predictions"');
});

it('returns unchanged latest brief payloads in game order with bounded reads and no forecast queries', function () {
    config(['prediction_lifecycle.canonical_pipeline.nfl' => true, 'prediction_lifecycle.canonical_reads.nfl' => false]);
    $expected = [];
    foreach (range(1, 26) as $index) {
        $game = researchSlateRetirementGame();
        foreach (['older', 'latest'] as $version) {
            $revision = ResearchRevision::create(['game_id' => $game->id, 'input_hash' => hash('sha256', $game->id.$version),
                'baseline' => ['predicted_total' => 40], 'revised' => ['predicted_total' => 42],
                'evidence' => ['unused' => str_repeat('large', 1000)], 'brief' => ['version' => $version],
                'market' => [], 'created_at' => now()]);
        }
        $expected[] = ['revision_id' => $revision->id, 'baseline' => $revision->baseline, 'revised' => $revision->revised,
            'brief' => $revision->brief, 'market' => $revision->market];
    }
    researchSlateRetirementGame();
    [$exit, $queries] = researchSlateRetirementQueries(fn () => Artisan::call('nfl:research-pipeline', ['--briefs' => true]));
    $rows = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim(Artisan::output())));
    expect($exit)->toBe(0)->and($rows)->toBe($expected)
        ->and($queries->filter(fn ($q) => str_contains($q['query'], 'from "nfl_research_revisions"')))->toHaveCount(2)
        ->and($queries->pluck('query')->implode(' '))->not->toContain('nfl_predictions')->not->toContain('from "predictions"')->not->toContain('"evidence"');
});

it('scopes explicit game filters before loading slate relations and briefs', function () {
    $game = researchSlateRetirementGame();
    researchSlateRetirementGame();
    [$exit, $queries] = researchSlateRetirementQueries(fn () => Artisan::call('nfl:research-pipeline', ['--briefs' => true, '--game-id' => [$game->id]]));
    $gameQuery = $queries->first(fn ($q) => str_contains($q['query'], 'from "nfl_games"'));
    expect($exit)->toBe(0)->and($gameQuery['query'])->toContain('"id" in (?)')
        ->and($gameQuery['bindings'])->toContain($game->id)
        ->and($queries->pluck('query')->implode(' '))->not->toContain('nfl_predictions');
});
