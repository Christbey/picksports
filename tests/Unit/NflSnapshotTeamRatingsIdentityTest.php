<?php

use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Services\Api\V2\NflSnapshotTeamRatings;
use Illuminate\Database\Eloquent\Model;

it('declines malformed frozen participant identity rather than integer casting it', function (mixed $id) {
    $snapshot = new class extends Model {};
    $snapshot->inputs = ['home' => ['team_id' => $id, 'elo' => 1625], 'away' => ['team_id' => 16, 'elo' => 1590]];
    $run = (new class extends Model {})->setRelation('inputSnapshot', $snapshot);
    $prediction = (new CanonicalPrediction(['sport' => 'nfl']))->setRelation('calculationRun', $run);
    $game = new Game(['home_team_id' => 4, 'away_team_id' => 16]);
    expect((new NflSnapshotTeamRatings)->forPrediction($prediction, $game))->toBe(['home' => null, 'away' => 1590.0]);
})->with(['fractional' => [4.5], 'trailing text' => ['4junk']]);

it('retains genuine captured participant ratings and independently unknown sides', function () {
    $snapshot = new class extends Model {};
    $run = (new class extends Model {})->setRelation('inputSnapshot', $snapshot);
    $prediction = (new CanonicalPrediction(['sport' => 'nfl']))->setRelation('calculationRun', $run);
    $game = new Game(['home_team_id' => 4, 'away_team_id' => 16]);
    $reader = new NflSnapshotTeamRatings;
    foreach ([4, '4'] as $id) {
        $snapshot->inputs = ['home' => ['team_id' => $id, 'elo' => 1625], 'away' => ['team_id' => 16, 'elo' => null]];
        expect($reader->forPrediction($prediction, $game))->toBe(['home' => 1625.0, 'away' => null]);
    }
    $prediction->setRelation('calculationRun', null);
    expect($reader->forPrediction($prediction, $game))->toBe(['home' => null, 'away' => null]);
});
