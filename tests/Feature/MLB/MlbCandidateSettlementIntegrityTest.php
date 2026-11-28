<?php

use App\Models\BetSettlement;
use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\PlayerProp;
use App\Models\MLB\Team;
use App\Services\MLB\Picks\BetDecisionRecorder;
use App\Services\MLB\Picks\MlbPickGradingService;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Str;

function integrityMlbCandidate(array $overrides = []): array
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 2]);
    $candidate = PickCandidate::create(['season' => 2026, 'game_id' => $game->id,
        'generation_run_id' => (string) Str::uuid(), 'market_type' => 'moneyline', 'market_key' => 'h2h',
        'side' => 'home', 'price' => -110, 'book' => 'entry-book', 'is_bet' => true,
        'is_tracking_only' => true, 'generated_at' => now()->subHours(3), 'game_start_at' => now()->subHours(2),
        ...$overrides]);
    $decision = app(BetDecisionRecorder::class)->record($candidate->refresh());

    return [$game, $candidate, $decision];
}

it('rolls back candidate grading when its settlement write fails and safely retries', function () {
    [, $candidate, $decision] = integrityMlbCandidate();
    $fail = true;
    BetSettlement::saving(function ($receipt) use ($decision, &$fail) {
        if ($receipt->bet_decision_id === $decision->id && $fail) {
            $fail = false;
            throw new RuntimeException('synthetic receipt failure');
        }
    });
    expect(fn () => app(MlbPickGradingService::class)->grade(2026))->toThrow(RuntimeException::class);
    expect($candidate->fresh()->graded_at)->toBeNull()
        ->and(BetSettlement::where('bet_decision_id', $decision->id)->exists())->toBeFalse();
    expect(app(MlbPickGradingService::class)->grade(2026))->toBe(1)
        ->and(BetSettlement::where('bet_decision_id', $decision->id)->count())->toBe(1);
});

it('preserves a shared writer receipt provenance and first settlement time', function () {
    [, $candidate, $decision] = integrityMlbCandidate();
    $receipt = BetSettlement::create(['bet_decision_id' => $decision->id, 'result_status' => 'win',
        'result_value' => 3, 'profit_units' => 0.909, 'settled_at' => now()->subHour(), 'graded_at' => now()->subHour(),
        'closing_line' => -1.5, 'closing_price' => -120, 'clv' => 0.1,
        'metadata' => ['home_score' => 5, 'away_score' => 2, 'closing_quote_id' => 123, 'unknown_provenance' => ['retain' => true]]]);
    $before = $receipt->fresh();
    app(MlbPickGradingService::class)->grade(2026);
    $after = $receipt->fresh();
    expect($after->settled_at->toIso8601String())->toBe($before->settled_at->toIso8601String())
        ->and((float) $after->closing_line)->toBe(-1.5)->and($after->closing_price)->toBe(-120)
        ->and($after->metadata['closing_quote_id'])->toBe(123)
        ->and($after->metadata['unknown_provenance'])->toBe(['retain' => true]);
});

it('has one consistent receipt regardless of settlement entry point order', function ($sharedFirst) {
    [, $candidate, $decision] = integrityMlbCandidate();
    if ($sharedFirst) {
        $this->artisan('sports:settle-bet-decisions', ['--sport' => 'mlb'])->assertSuccessful();
    }
    app(MlbPickGradingService::class)->grade(2026);
    $before = $decision->settlement()->firstOrFail()->toArray();
    $this->artisan('sports:settle-bet-decisions', ['--sport' => 'mlb'])->assertSuccessful();
    app(BetDecisionRecorder::class)->settle($candidate);
    expect($decision->settlement()->firstOrFail()->toArray())->toBe($before);
})->with([true, false]);

it('grades a full-game moneyline tie as a push', function () {
    [$game, $candidate, $decision] = integrityMlbCandidate();
    $game->update(['home_score' => 2, 'away_score' => 2]);
    app(MlbPickGradingService::class)->grade(2026);
    expect($candidate->fresh()->result_status)->toBe('push')
        ->and((float) $candidate->fresh()->result_profit_units)->toBe(0.0)
        ->and($decision->settlement()->firstOrFail()->result_status)->toBe('push');
});

it('explicitly corrects current scores once and retains the raw prior receipt and closing evidence', function () {
    [$game, $candidate, $decision] = integrityMlbCandidate();
    app(MlbPickGradingService::class)->grade(2026);
    $receipt = $decision->settlement()->firstOrFail();
    $receipt->update(['closing_price' => -125, 'clv' => 0.15, 'metadata' => [...$receipt->metadata, 'opaque_provenance' => ['raw' => 'retain']]]);
    $before = $receipt->fresh()->toArray();
    $game->update(['home_score' => 1, 'away_score' => 6]);
    $this->artisan('mlb:reconcile-pick-settlements', ['--ids' => (string) $candidate->id])->assertSuccessful();
    expect($receipt->fresh()->toArray())->toBe($before);
    $this->artisan('mlb:reconcile-pick-settlements', ['--ids' => (string) $candidate->id, '--apply' => true])->assertSuccessful();
    $after = $receipt->fresh();
    expect($after->result_status)->toBe('loss')->and($candidate->fresh()->result_status)->toBe('loss')
        ->and($after->metadata['mlb_candidate_corrections'])->toHaveCount(1)
        ->and(app(CanonicalPayloadHasher::class)->hash($after->metadata['mlb_candidate_corrections'][0]['previous_settlement']))
        ->toBe(app(CanonicalPayloadHasher::class)->hash($before))
        ->and($after->metadata['opaque_provenance'])->toBe(['raw' => 'retain'])
        ->and($after->closing_price)->toBe(-125)->and((float) $after->clv)->toBe(0.15)
        ->and($after->settled_at->toIso8601String())->toBe($receipt->settled_at->toIso8601String());
    $snapshot = $after->toArray();
    $this->artisan('mlb:reconcile-pick-settlements', ['--ids' => (string) $candidate->id, '--apply' => true])->assertSuccessful();
    expect($receipt->fresh()->toArray())->toBe($snapshot);
});

it('recovers an already graded candidate whose legacy settlement write was lost', function () {
    [, $candidate, $decision] = integrityMlbCandidate(['graded_at' => now(), 'result_status' => 'loss', 'result_value' => -99]);
    $this->artisan('mlb:reconcile-pick-settlements', ['--ids' => (string) $candidate->id, '--apply' => true])->assertSuccessful();
    expect($candidate->fresh()->result_status)->toBe('win')
        ->and($decision->settlement()->firstOrFail()->result_status)->toBe('win');
});

it('rejects implicit broad reconciliation and invalid or oversized candidate selections', function ($ids) {
    $this->artisan('mlb:reconcile-pick-settlements', ['--ids' => $ids, '--apply' => true])->assertFailed();
    expect(BetSettlement::count())->toBe(0);
})->with(['', '0', '1,wrong', implode(',', range(1, 101))]);

it('leaves mismatched frozen entry identities and incomplete periods pending', function ($overrides) {
    [$game, $candidate, $decision] = integrityMlbCandidate($overrides);
    if ($candidate->market_type === 'moneyline') {
        $candidate->update(['side' => 'away']);
    } else {
        $game->update(['home_linescores' => [1, 0], 'away_linescores' => [0, 1]]);
    }
    $this->artisan('mlb:reconcile-pick-settlements', ['--ids' => (string) $candidate->id, '--apply' => true])->assertFailed();
    expect($candidate->fresh()->graded_at)->toBeNull()->and($decision->settlement()->exists())->toBeFalse();
})->with([[[]], [['market_type' => 'first_5_moneyline', 'market_key' => 'h2h_1st_5_innings']]]);

it('corrects a changed stored inning outcome even when the final game scores are unchanged', function () {
    [$game, $candidate, $decision] = integrityMlbCandidate(['market_type' => 'first_3_moneyline', 'market_key' => 'h2h_1st_3_innings']);
    $game->update(['home_linescores' => [2, 0, 0], 'away_linescores' => [0, 0, 0]]);
    app(MlbPickGradingService::class)->grade(2026);
    expect($candidate->fresh()->result_status)->toBe('win');
    $game->update(['home_linescores' => [0, 0, 0], 'away_linescores' => [1, 0, 0]]);
    app(BetDecisionRecorder::class)->settle($candidate);
    expect($candidate->fresh()->result_status)->toBe('loss')
        ->and($decision->settlement()->firstOrFail()->metadata['mlb_candidate_corrections'])->toHaveCount(1);
});

it('uses the frozen prop line and audits a corrected actual without crossing game or market identity', function () {
    [$game, $candidate, $decision] = integrityMlbCandidate(['market_type' => 'player_prop', 'market_key' => 'batter_hits', 'side' => 'over', 'line' => 1.5]);
    $prop = PlayerProp::create(['game_id' => $game->id, 'player_name' => 'Synthetic Batter',
        'market' => 'batter_hits', 'line' => 9.5, 'actual_value' => 2]);
    $candidate->update(['feature_snapshot' => ['prop_id' => $prop->id]]);
    app(MlbPickGradingService::class)->grade(2026);
    expect($candidate->fresh()->result_status)->toBe('win');
    $prop->update(['actual_value' => 1]);
    app(BetDecisionRecorder::class)->settle($candidate);
    $receipt = $decision->settlement()->firstOrFail();
    expect($receipt->result_status)->toBe('loss')->and($receipt->metadata['mlb_candidate_corrections'])->toHaveCount(1);
    $before = $receipt->toArray();
    $prop->update(['market' => 'batter_home_runs', 'actual_value' => 3]);
    expect(app(MlbPickGradingService::class)->gradeCandidate($candidate->id))->toBeNull()
        ->and($receipt->fresh()->toArray())->toBe($before);
});

it('leaves excluded or nonfinal targeted candidates unchanged', function ($status) {
    [$game, $candidate, $decision] = integrityMlbCandidate();
    $game->update(['status' => $status]);
    expect(app(MlbPickGradingService::class)->gradeCandidate($candidate->id))->toBeNull()
        ->and($candidate->fresh()->graded_at)->toBeNull()->and($decision->settlement()->exists())->toBeFalse();
})->with(['STATUS_IN_PROGRESS', 'STATUS_SUSPENDED', 'STATUS_POSTPONED']);

it('repairs a candidate drift without rewriting an already matching receipt', function () {
    [, $candidate, $decision] = integrityMlbCandidate();
    app(MlbPickGradingService::class)->grade(2026);
    $before = $decision->settlement()->firstOrFail()->toArray();
    $candidate->update(['result_status' => 'loss', 'result_profit_units' => -1]);
    app(MlbPickGradingService::class)->gradeCandidate($candidate->id);
    expect($candidate->fresh()->result_status)->toBe('win')
        ->and($decision->settlement()->firstOrFail()->toArray())->toBe($before);
});

it('admits both shared final status representations in ordinary ungraded candidate grading', function ($status) {
    [$game, $candidate] = integrityMlbCandidate();
    $game->update(['status' => $status]);
    expect(app(MlbPickGradingService::class)->grade(2026))->toBe(1)
        ->and($candidate->fresh()->result_status)->toBe('win');
})->with(['STATUS_FINAL', 'STATUS_FULL_TIME']);

it('fails closed on ambiguous decisions for one candidate without merging or deleting history', function () {
    [, $candidate, $decision] = integrityMlbCandidate();
    $duplicate = $decision->replicate();
    $duplicate->decision_hash = hash('sha256', (string) Str::uuid());
    $duplicate->save();
    expect(app(MlbPickGradingService::class)->gradeCandidate($candidate->id) === null)->toBeTrue()
        ->and($candidate->fresh()->graded_at)->toBeNull()->and(BetSettlement::count())->toBe(0)
        ->and($decision->fresh())->not->toBeNull()->and($duplicate->fresh())->not->toBeNull();
});

it('audits and repairs a corrupted receipt even when its source fingerprint is unchanged', function ($field, $value) {
    [, $candidate, $decision] = integrityMlbCandidate();
    app(MlbPickGradingService::class)->grade(2026);
    $receipt = $decision->settlement()->firstOrFail();
    if ($field === 'shadow_profit_units') {
        $receipt->update(['metadata' => [...$receipt->metadata, $field => $value]]);
    } else {
        $receipt->update([$field => $value]);
    }
    $corrupt = $receipt->fresh()->toArray();
    app(MlbPickGradingService::class)->gradeCandidate($candidate->id);
    $after = $receipt->fresh();
    expect($after->result_status)->toBe('win')->and((float) $after->result_value)->toBe(3.0)
        ->and((float) $after->profit_units)->toBe(0.909)
        ->and(round((float) $after->metadata['shadow_profit_units'], 4))->toBe(0.909)
        ->and($after->metadata['mlb_candidate_corrections'])->toHaveCount(1)
        ->and(app(CanonicalPayloadHasher::class)->hash($after->metadata['mlb_candidate_corrections'][0]['previous_settlement']))
        ->toBe(app(CanonicalPayloadHasher::class)->hash($corrupt));
    $beforeRepeat = $after->toArray();
    app(MlbPickGradingService::class)->gradeCandidate($candidate->id);
    expect($receipt->fresh()->toArray())->toBe($beforeRepeat);
})->with([['result_status', 'loss'], ['result_value', -99], ['profit_units', -1], ['shadow_profit_units', -1]]);
