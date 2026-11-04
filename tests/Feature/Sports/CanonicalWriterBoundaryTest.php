<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\CanonicalPrediction;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\CBB\CbbSeasonInitializer;
use Illuminate\Support\Facades\Http;

it('blocks direct active legacy writers for every cut-over sport before creating any forecast', function (string $sport) {
    Http::preventStrayRequests();
    config(["prediction_lifecycle.canonical_pipeline.{$sport}" => true, "prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $gameClass = $context->models['game'];
    $legacyClass = $context->models['prediction'];
    $game = $gameClass::factory()->make(['status' => 'STATUS_SCHEDULED']);
    if ($sport === 'cbb') {
        $this->mock(CbbSeasonInitializer::class)->shouldNotReceive('initialize');
        $game->setRelation('sportEvent', new SportEvent(['starts_at' => now()->addDay()]));
        $teamClass = $context->models['team'];
        $game->setRelation('homeTeam', $teamClass::factory()->make(['espn_id' => '101', 'school' => 'Home', 'abbreviation' => 'HME']));
        $game->setRelation('awayTeam', $teamClass::factory()->make(['espn_id' => '102', 'school' => 'Away', 'abbreviation' => 'AWY']));
        $game->espn_event_id = '12345';
    }
    $writer = $sport === 'nfl' ? GeneratePredictionFromHistoricalElo::class : 'App\\Actions\\'.strtoupper($sport).'\\GeneratePrediction';

    expect(fn () => app($writer)->execute($game))->toThrow(LogicException::class, 'Legacy pregame writes are retired')
        ->and($legacyClass::count())->toBe(0)->and(CanonicalPrediction::count())->toBe(0);
})->with(['nfl', 'cfb', 'nba', 'cbb', 'wcbb', 'mlb', 'wnba']);
