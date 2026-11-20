<?php

use App\Enums\PredictionSport;
use App\Http\Resources\UserBetResource;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\SportEvent;
use App\Models\UserBet;
use App\Services\Betting\UserBetPredictionReferenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('keeps unknown stored morph classes unresolved during both eager and lazy loading', function () {
    $bet = UserBet::factory()->create(['prediction_type' => 'Vendor\\Untrusted\\Prediction', 'prediction_id' => 9, 'prediction_sport' => 'nfl']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($bet->prediction)->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();

    expect(UserBet::with('prediction')->findOrFail($bet->id)->prediction)->toBeNull()
        ->and($bet->prediction()->first())->toBeNull()
        ->and(app(UserBetPredictionReferenceResolver::class)->fromStoredBet($bet))->toBeNull();
});

it('rejects mismatched sport and event identities while retaining valid canonical and legacy references', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    $otherEvent = SportEvent::factory()->create(['sport' => 'nfl']);
    $canonical = CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nfl', 'detail_sport' => 'nfl']);
    $valid = UserBet::factory()->create(['prediction_type' => CanonicalPrediction::class, 'prediction_id' => $canonical->id, 'prediction_sport' => 'nfl', 'sport_event_id' => $event->id]);
    $wrongSport = UserBet::factory()->create(['prediction_type' => CanonicalPrediction::class, 'prediction_id' => $canonical->id, 'prediction_sport' => 'nba', 'sport_event_id' => $event->id]);
    $wrongEvent = UserBet::factory()->create(['prediction_type' => CanonicalPrediction::class, 'prediction_id' => $canonical->id, 'prediction_sport' => 'nfl', 'sport_event_id' => $otherEvent->id]);
    $bets = UserBet::with('prediction')->get()->keyBy('id');

    expect($bets[$valid->id]->prediction?->is($canonical))->toBeTrue()
        ->and($bets[$wrongSport->id]->prediction)->toBeNull()
        ->and($wrongSport->prediction()->first())->toBeNull()
        ->and($wrongEvent->prediction()->first())->toBeNull()
        ->and($bets[$wrongEvent->id]->prediction)->toBeNull()
        ->and(app(UserBetPredictionReferenceResolver::class)->fromStoredBet($bets[$wrongEvent->id]))->toBeNull()
        ->and($wrongEvent->fresh()->sport_event_id)->toBe($otherEvent->id);

    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id]);
    $legacyBet = UserBet::factory()->create(['prediction_type' => Prediction::class, 'prediction_id' => $legacy->id, 'prediction_sport' => null, 'sport_event_id' => $event->id]);
    expect(UserBet::with('prediction')->findOrFail($legacyBet->id)->prediction?->is($legacy))->toBeTrue();
});

it('does not cast numeric-looking malformed references into different forecast IDs', function () {
    $resolver = app(UserBetPredictionReferenceResolver::class);
    foreach (['1e2', '1.9', '-1', '0', 'bogus'] as $id) {
        expect(fn () => $resolver->resolve(PredictionSport::NFL, $id))->toThrow(ValidationException::class);
    }
    $legacy = new UserBet(['prediction_type' => Prediction::class, 'prediction_id' => 999999, 'prediction_sport' => 'nfl']);
    expect($resolver->fromStoredBet($legacy)?->predictionId)->toBe(999999);
});

it('omits rejected presentation references while preserving unresolved allowlisted legacy ledger IDs', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    $canonical = CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nfl', 'detail_sport' => 'nfl']);
    $wrongSport = UserBet::factory()->create(['prediction_type' => CanonicalPrediction::class, 'prediction_id' => $canonical->id, 'prediction_sport' => 'nba', 'sport_event_id' => $event->id]);
    $unknown = UserBet::factory()->create(['prediction_type' => 'Vendor\\Untrusted\\Prediction', 'prediction_id' => 99, 'prediction_sport' => 'nfl', 'sport_event_id' => null]);
    $missingLegacy = UserBet::factory()->create(['prediction_type' => Prediction::class, 'prediction_id' => 999999, 'prediction_sport' => 'nfl', 'sport_event_id' => null]);
    $rows = UserBet::with(['prediction', 'sportEvent'])->get()->keyBy('id');
    $serialize = fn (UserBet $bet): array => (new UserBetResource($bet))->resolve();

    expect($serialize($rows[$wrongSport->id])['prediction_reference'])->toBeNull()
        ->and($serialize($rows[$unknown->id])['prediction_reference'])->toBeNull()
        ->and($serialize($rows[$missingLegacy->id])['prediction_reference']['id'])->toBe(999999);
});

it('leaves corrupt stored sport values unresolved rather than throwing or inferring another sport', function () {
    $bet = UserBet::factory()->create(['prediction_type' => Prediction::class, 'prediction_id' => 99, 'prediction_sport' => 'nfl']);
    DB::table('user_bets')->where('id', $bet->id)->update(['prediction_sport' => 'unsupported-stored-sport']);
    $loaded = UserBet::with('prediction')->findOrFail($bet->id);

    expect($loaded->normalizedPredictionSport())->toBeNull()
        ->and($loaded->prediction)->toBeNull()
        ->and((new UserBetResource($loaded))->resolve()['prediction_reference'])->toBeNull();
});
