<?php

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameDepthChartLink;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Services\NFL\GameDepthChartLinkRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Illuminate\Support\Str;

function depthLinkFixture(): array
{
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'season' => 2026, 'game_date' => '2026-10-04', 'game_time' => '17:00:00', 'status' => 'STATUS_SCHEDULED']);
    $snapshot = DepthChartSnapshot::create([
        'snapshot_uuid' => (string) Str::uuid(), 'team_id' => $game->home_team_id,
        'espn_team_id' => $game->homeTeam->espn_id, 'season' => 2026,
        'provider' => 'espn', 'observed_at' => now()->subHour(),
        'payload_hash' => hash('sha256', 'depth'), 'entry_count' => 1,
    ]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $payload = [
        'game_id' => $game->id, 'team_id' => $game->home_team_id, 'side' => 'home',
        'snapshot_id' => $snapshot->id, 'snapshot_uuid' => $snapshot->snapshot_uuid,
        'observed_at' => $snapshot->observed_at->toIso8601String(), 'as_of' => now()->toIso8601String(),
    ];

    return [$game, $snapshot, $prediction, $payload];
}

function recordDepthLinkRevision(Game $game, Prediction $prediction, array $payload): PredictionFeatureSnapshot
{
    return app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', [
        'model_metadata' => ['quarterback' => ['home' => ['depth_chart_game_link' => $payload]]],
    ]);
}

beforeEach(function () {
    $this->travelTo('2026-10-01 12:00:00');
});

it('records immutable game side associations for each saved forecast revision', function () {
    [$game, $snapshot, $prediction, $payload] = depthLinkFixture();
    $first = recordDepthLinkRevision($game, $prediction, $payload);
    app(GameDepthChartLinkRecorder::class)->record($game, $first);
    expect($game->homeDepthChartLinks()->count())->toBe(1);
    $newSnapshot = $snapshot->replicate();
    $newSnapshot->snapshot_uuid = (string) Str::uuid();
    $newSnapshot->observed_at = now();
    $newSnapshot->save();
    $payload['snapshot_id'] = $newSnapshot->id;
    $payload['snapshot_uuid'] = $newSnapshot->snapshot_uuid;
    $second = recordDepthLinkRevision($game, $prediction, $payload);
    $links = $game->depthChartLinks()->orderBy('id')->get();
    expect($links)->toHaveCount(2)
        ->and($game->awayDepthChartLinks()->count())->toBe(0)
        ->and($links[0]->snapshot->id)->toBe($snapshot->id)
        ->and($links[0]->predictionFeatureSnapshot->id)->toBe($first->id)
        ->and($links[1]->predictionFeatureSnapshot->id)->toBe($second->id)
        ->and($links[1]->snapshot->id)->toBe($newSnapshot->id)
        ->and($links[0]->identity_status)->toBe('projected_not_confirmed_starter')
        ->and($links[0]->selection_mode)->toBe('observed_pregame');
    expect(fn () => $links[0]->update(['source' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $links[1]->delete())->toThrow(LogicException::class);
});

it('rejects mismatched and unavailable snapshots', function (string $invalid) {
    [$game, $snapshot, $prediction, $payload] = depthLinkFixture();
    match ($invalid) {
        'wrong_team' => $payload['team_id'] = $game->away_team_id,
        'wrong_game' => $payload['game_id'] = $game->id + 1,
        'wrong_uuid' => $payload['snapshot_uuid'] = (string) Str::uuid(),
        'future_observation' => $snapshot->update(['observed_at' => now()->addHour()]),
        'future_publication' => $snapshot->update(['source_updated_at' => now()->addHour()]),
        'future_as_of' => $payload['as_of'] = now()->addHour()->toIso8601String(),
        'wrong_season' => $snapshot->update(['season' => 2025]),
    };
    recordDepthLinkRevision($game, $prediction, $payload);
    expect(GameDepthChartLink::count())->toBe(0);
})->with(['wrong_team', 'wrong_game', 'wrong_uuid', 'future_observation', 'future_publication', 'future_as_of', 'wrong_season']);

it('preserves only pregame observations when recording a historical reconstruction', function () {
    [$game, $snapshot, $prediction, $payload] = depthLinkFixture();
    $this->travelTo('2026-10-06 12:00:00');
    recordDepthLinkRevision($game, $prediction, $payload);
    expect($game->depthChartLinks()->first()->selection_mode)->toBe('historical_reconstruction');
    $snapshot->update(['observed_at' => now()->subDay()]);
    $payload['as_of'] = now()->toIso8601String();
    recordDepthLinkRevision($game, $prediction, $payload);
    expect($game->depthChartLinks()->count())->toBe(1);
});

it('does not associate charts merely because an unrelated prediction was saved', function () {
    [$game, $snapshot, $prediction] = depthLinkFixture();
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', []);
    expect(GameDepthChartLink::count())->toBe(0);
});

it('rejects ambiguous same-side selections but accepts repeated identical evidence', function (bool $conflicting) {
    [$game, $snapshot, $prediction, $payload] = depthLinkFixture();
    $other = $payload;
    if ($conflicting) {
        $newSnapshot = $snapshot->replicate();
        $newSnapshot->snapshot_uuid = (string) Str::uuid();
        $newSnapshot->save();
        $other['snapshot_id'] = $newSnapshot->id;
        $other['snapshot_uuid'] = $newSnapshot->snapshot_uuid;
    }
    app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', [
        'model_metadata' => [
            'qb_form' => ['depth_chart_game_link' => $payload],
            'game_depth_charts' => ['home' => ['depth_chart_game_link' => $other]],
        ],
    ]);
    expect(GameDepthChartLink::count())->toBe($conflicting ? 0 : 1);
})->with([true, false]);

it('skips malformed evidence timestamps without failing forecast persistence', function (mixed $asOf) {
    [$game, $snapshot, $prediction, $payload] = depthLinkFixture();
    $payload['as_of'] = $asOf;
    $revision = recordDepthLinkRevision($game, $prediction, $payload);
    expect($revision->exists)->toBeTrue()
        ->and(GameDepthChartLink::count())->toBe(0);
})->with(['invalid timestamp', '', null, [['invalid']]]);

it('requires a revision generation timestamp before linking evidence', function () {
    [$game, $snapshot, $prediction, $payload] = depthLinkFixture();
    $revision = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'nfl', []);
    $revision->generated_at = null;
    $revision->model_metadata = ['depth_chart_game_link' => $payload];
    app(GameDepthChartLinkRecorder::class)->record($game, $revision);
    expect(GameDepthChartLink::count())->toBe(0);
});
