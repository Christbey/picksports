<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\LivePredictionSnapshot;
use App\Models\MLB\Game;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\Predictions\LivePredictionStore;
use Tests\Support\ObservedMlbGameFixture;

beforeEach(fn () => $this->travelTo('2026-10-28 10:00:00'));
afterEach(fn () => $this->travelBack());
function nullableLiveFixture(string $sport = 'wnba', bool $withTotal = false, bool $zeroTotal = false): array
{
    $context = app(SportContextResolver::class)->resolve($sport);
    $event = SportEvent::factory()->create(['sport' => $sport, 'starts_at' => now()->addHour()]);
    $team = $context->models['team'];
    $gameClass = $context->models['game'];
    $game = $gameClass::factory()->create([
        'home_team_id' => $team::factory()->create()->id, 'away_team_id' => $team::factory()->create()->id,
        'sport_event_id' => $event->id, 'status' => 'STATUS_SCHEDULED', 'season' => 2026,
        'game_date' => now()->toDateString(), 'game_time' => '11:00:00',
    ]);
    if ($game instanceof Game) {
        $game = ObservedMlbGameFixture::observe($game, $event->starts_at);
    }
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $inputAttributes = ['inputs' => ['home' => ['team_id' => $game->home_team_id], 'away' => ['team_id' => $game->away_team_id]], 'sport' => $sport, 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => $event->starts_at];
    if ($game instanceof Game) {
        $inputAttributes = ObservedMlbGameFixture::snapshotAttributes($game, $inputAttributes['inputs'] ?? []);
    }
    $input = EventInputSnapshot::factory()->create($inputAttributes);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded', 'output_hash' => str_repeat('a', 64)]);
    $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'output_hash' => $run->output_hash, 'phase' => 'pregame', 'publication_state' => 'draft',
        'detail_source' => null, 'detail_id' => null, 'detail_sport' => null,
        'output_metadata' => ['input_quality' => ['qualified' => true], 'market_conventions' => ['spread' => 'sportsbook_home_line']]]);
    $total = $zeroTotal ? 0.0 : ($sport === 'mlb' ? 8.5 : (in_array($sport, ['nfl', 'cfb'], true) ? 45.0 : 150.0));
    foreach ([['moneyline', 'home', null, 0.65], ['spread', 'home', -4.0, null], ['total', 'combined', $total, null]] as [$type, $side, $line, $probability]) {
        if ($type === 'total' && ! $withTotal) {
            continue;
        }
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type, 'selection' => $side,
            'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $total];
}

it('does not publish a default total when the selected live baseline has no total market', function (bool $canonical, string $sport) {
    [$game, $native] = nullableLiveFixture($sport);
    $context = app(SportContextResolver::class)->resolve($sport);
    $legacyClass = $context->models['prediction'];
    $legacy = $legacyClass::factory()->create(['game_id' => $game->id, 'predicted_spread' => 4.0, 'predicted_total' => null, 'win_probability' => 0.65]);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => $canonical]);
    $this->travel(2)->hours();
    $game->update(['status' => 'STATUS_IN_PROGRESS', 'period' => 1, 'game_clock' => $sport === 'wcbb' ? '20:00' : '10:00', 'home_score' => 0, 'away_score' => 0]);
    $baseline = app(LivePredictionStore::class)->baseline($game->fresh());
    expect($baseline)->not->toBeNull()->and($baseline->predicted_total)->toBeNull();
    $output = app('App\\Actions\\'.strtoupper($sport).'\\UpdateLivePrediction')->execute($game->fresh());
    $snapshot = LivePredictionSnapshot::query()->where('game_id', $game->id)->where('sport', $sport)->sole();
    $resource = app(SportPredictionReader::class)->resource($context, $canonical ? $native : $legacy)->resolve(request());
    expect($output['live_predicted_spread'])->not->toBeNull()
        ->and($output['live_win_probability'])->not->toBeNull()
        ->and(['action' => $output['live_predicted_total'], 'snapshot' => $snapshot->projection['live_predicted_total'], 'resource' => $resource['live_predicted_total']])->toBe(['action' => null, 'snapshot' => null, 'resource' => null]);
})->with([[false, 'wnba'], [true, 'wnba'], [false, 'wcbb'], [true, 'wcbb']]);

it('keeps a known selected total and the existing start-of-game math', function (bool $canonical, string $sport, bool $zeroTotal) {
    [$game, $native, $knownTotal] = nullableLiveFixture($sport, true, $zeroTotal);
    $context = app(SportContextResolver::class)->resolve($sport);
    $legacyClass = $context->models['prediction'];
    $legacyClass::factory()->create(['game_id' => $game->id, 'predicted_spread' => 4.0, 'predicted_total' => $knownTotal, 'win_probability' => 0.65]);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => $canonical]);
    $this->travel(2)->hours();
    $game->update(['status' => 'STATUS_IN_PROGRESS', 'period' => 1, 'game_clock' => $sport === 'wcbb' ? '20:00' : '10:00', 'home_score' => 0, 'away_score' => 0]);
    $output = app('App\\Actions\\'.strtoupper($sport).'\\UpdateLivePrediction')->execute($game->fresh());
    expect($output)->toBe(['live_predicted_spread' => 4.0, 'live_win_probability' => 0.65, 'live_predicted_total' => $knownTotal, 'live_seconds_remaining' => 2400]);
})->with([[false, 'wnba', false], [true, 'wnba', false], [false, 'wcbb', false], [true, 'wcbb', false], [false, 'wnba', true], [true, 'wnba', true], [false, 'wcbb', true], [true, 'wcbb', true]]);
