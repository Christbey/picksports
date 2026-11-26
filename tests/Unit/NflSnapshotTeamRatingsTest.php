<?php

use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Services\Api\V2\NflSnapshotTeamRatings;
use Illuminate\Database\Eloquent\Model;

it('reads finite positive ratings only from matching frozen team inputs', function () {
    $snapshot = new class extends Model {};
    $snapshot->inputs = ['home' => ['team_id' => 4, 'elo' => 1625], 'away' => ['team_id' => 16, 'elo' => 1590]];
    $run = (new class extends Model {})->setRelation('inputSnapshot', $snapshot);
    $prediction = (new CanonicalPrediction(['sport' => 'nfl']))->setRelation('calculationRun', $run);
    $game = new Game(['home_team_id' => 4, 'away_team_id' => 16]);
    $reader = new NflSnapshotTeamRatings;
    expect($reader->forPrediction($prediction, $game))->toBe(['home' => 1625.0, 'away' => 1590.0]);
    $snapshot->inputs = ['home' => ['team_id' => 99, 'elo' => 1700], 'away' => ['team_id' => 16, 'elo' => 'invalid']];
    expect($reader->forPrediction($prediction, $game))->toBe(['home' => null, 'away' => null]);
    $prediction->setRelation('calculationRun', null);
    expect($reader->forPrediction($prediction, $game))->toBe(['home' => null, 'away' => null]);
});
