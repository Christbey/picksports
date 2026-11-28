<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use App\Services\CFB\Scoring\CfbTrainingSourceProvenance;
use Illuminate\Support\Carbon;

/** Synthetic algorithm fixtures explicitly observe context; never promote production legacy history. */
function cfbObservedAcceptedFixture(array $attributes): GameDataCheck
{
    $acceptedAt = $attributes['accepted_at'] ?? null;
    unset($attributes['accepted_at']);
    $create = static function () use ($attributes, $acceptedAt): GameDataCheck {
        return (new GameDataCheck)->getConnection()->transaction(function () use ($attributes, $acceptedAt): GameDataCheck {
            $game = Game::whereKey($attributes['game_id'])->lockForUpdate()->firstOrFail();
            $check = GameDataCheck::create($attributes);
            $recorder = app(CfbSourceAssessmentRecorder::class);
            $recorder->append($check, $recorder->context($game), 'observed_assessment');
            if ($acceptedAt !== null) {
                $check->update(['accepted_at' => $acceptedAt]);
            }

            return $check->fresh();
        });
    };

    return $acceptedAt !== null && Carbon::parse($acceptedAt)->isFuture()
        ? Carbon::withTestNow(Carbon::parse($acceptedAt), $create)
        : $create();
}

/** Observe an explicitly changed synthetic fixture context; not a historical source validator. */
function cfbReobserveSyntheticChecks(Game $game): void
{
    $game->getConnection()->transaction(function () use ($game): void {
        $recorder = app(CfbSourceAssessmentRecorder::class);
        $locked = $game->newQuery()->whereKey($game->id)->lockForUpdate()->firstOrFail();
        foreach (GameDataCheck::where('game_id', $game->id)->orderBy('id')->get() as $check) {
            $recorder->append($check, $recorder->context($locked), 'observed_assessment');
        }
    });
}

function cfbSyntheticTrainingProvenance(Game $game): array
{
    $proof = [];
    foreach (['boxscore', 'plays'] as $component) {
        $check = app(CfbDataReadiness::class)->acceptedCheck($game, $component);
        $receipt = app(CfbSourceAssessmentRecorder::class)->acceptanceReceipt($check);
        $proof[$component] = ['check_id' => $check->id, 'receipt_id' => $receipt->id,
            'source_hash' => $check->source_hash, 'context_hash' => $receipt->context_hash,
            'game_feature_hash' => app(CfbTrainingSourceProvenance::class)->gameFeatureHash($game)];
    }

    return ['policy' => 'cfb-observed-assessment-v1', 'games' => [$game->id => $proof]];
}
