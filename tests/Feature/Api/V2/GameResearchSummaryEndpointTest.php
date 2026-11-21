<?php

use App\Http\Middleware\UpdateUserLastActive;
use App\Models\User;
use App\Services\Api\V2\GameResearchPresentation;
use App\Services\Predictions\Review\CurrentPredictionReview;
use App\Services\Research\GameResearchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['subscriptions.enforce_tiers' => false]);
    // Normal auth activity tracking is unrelated to read-only analysis semantics.
    $this->withoutMiddleware(UpdateUserLastActive::class);
});

it('reads shared saved research by legacy and canonical game identity without writing', function (string $sport) {
    [$game] = PredictionReviewFixtures::game($sport);
    Sanctum::actingAs(User::factory()->admin()->create());
    $research = Mockery::mock(GameResearchService::class);
    $research->shouldReceive('forGame')->twice()->withArgs(fn ($resolved, $slug) => $resolved->id === $game->id && $slug === $sport)->andReturn([
        'schema_version' => 'game_research_v1', 'sport' => $sport, 'game_id' => $game->id,
        'sport_event_id' => $game->sport_event_id, 'status' => 'partial', 'applicability' => 'current',
        'supporting' => [['claim' => 'Stored completed-game sample.', 'evidence_kind' => 'application', 'internal_prompt' => 'private']],
        'concerns' => [], 'unresolved' => [], 'sources' => [], 'raw_payload' => ['secret' => true],
    ]);
    $research->shouldNotReceive('assess');
    $research->shouldReceive('enabled')->andReturn(false);
    app()->instance(GameResearchService::class, $research);
    $reviews = Mockery::mock(CurrentPredictionReview::class);
    $reviews->shouldReceive('forGame')->twice()->andReturn(['status' => 'pending', 'summary' => null, 'internal_input' => 'private']);
    app()->instance(CurrentPredictionReview::class, $reviews);
    DB::enableQueryLog();
    DB::flushQueryLog();
    foreach ([$game->id, $game->sportEvent->public_id] as $identity) {
        $this->getJson("/api/v2/sports/{$sport}/games/{$identity}/analysis/research")
            ->assertOk()->assertJsonPath('data.game_id', $game->id)
            ->assertJsonPath('data.status', 'partial')->assertJsonPath('data.review.status', 'pending')
            ->assertJsonMissingPath('data.raw_payload')->assertJsonMissingPath('data.supporting.0.internal_prompt')
            ->assertJsonMissingPath('data.review.internal_input')
            ->assertJsonPath('meta.contract', 'sports.games.analysis.research.show');
    }
    $writes = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query']));
    DB::disableQueryLog();
    expect($writes->pluck('query')->all())->toBe([]);
    Http::assertNothingSent();
})->with(['nba', 'nfl', 'mlb', 'cbb', 'wcbb', 'wnba', 'cfb']);

it('keeps shared research admin-only even with every prediction entitlement', function (bool $enforceTiers) {
    [$game] = PredictionReviewFixtures::game('nba');
    config(['subscriptions.enforce_tiers' => $enforceTiers, 'subscriptions.tier_bypass_user_ids' => []]);
    $user = User::factory()->create();
    foreach (['access-api', 'view-nba-predictions', 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    Sanctum::actingAs($user);
    $this->getJson("/api/v2/sports/nba/games/{$game->id}/analysis/research")->assertForbidden();
})->with([true, false]);

it('rejects cross-sport canonical IDs and unsupported sports', function () {
    [$game] = PredictionReviewFixtures::game('nba');
    Sanctum::actingAs(User::factory()->admin()->create());
    $this->getJson("/api/v2/sports/nfl/games/{$game->sportEvent->public_id}/analysis/research")->assertNotFound();
    $this->getJson("/api/v2/sports/unknown/games/{$game->id}/analysis/research")->assertNotFound();
});

it('removes unsafe source links and internal metadata from the research summary', function () {
    $summary = app(GameResearchPresentation::class)->present([
        'status' => 'partial', 'applicability' => 'current', 'evidence_hash' => 'internal',
        'supporting' => [['claim' => 'Stored finding', 'source_url' => 'javascript:alert(1)', 'raw_document' => 'private']],
        'sources' => [
            ['url' => 'https://example.com/report', 'title' => 'Saved source', 'body' => 'private'],
            ['url' => 'https://user:password@example.com/report'],
            ['url' => 'http://example.com/report'],
        ],
    ], ['status' => 'current', 'summary' => 'Saved interpretation', 'provider_exception' => 'private']);

    expect($summary['sources'])->toBe([['url' => 'https://example.com/report', 'title' => 'Saved source']])
        ->and($summary['supporting'][0])->toBe(['claim' => 'Stored finding', 'source_url' => null])
        ->and($summary)->not->toHaveKey('evidence_hash')
        ->and($summary['review'])->toBe(['status' => 'current', 'summary' => 'Saved interpretation']);
});

it('requires prediction disclosure permissions for admins when tiers are enforced', function (string $missing) {
    [$game] = PredictionReviewFixtures::game('nba');
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    $user = User::factory()->admin()->create();
    foreach (['access-api', 'view-nba-predictions', 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        if ($permission !== $missing) {
            $user->givePermissionTo($permission);
        }
    }
    Sanctum::actingAs($user);
    $this->getJson("/api/v2/sports/nba/games/{$game->id}/analysis/research")->assertForbidden();
})->with(['view-nba-predictions', 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value']);

it('documents optional evidence fields and separates review interpretation in OpenAPI', function () {
    Artisan::call('api:v2-openapi-generate', ['--stdout' => true]);
    $spec = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $schema = $spec['components']['schemas']['GameResearchSummaryData'];
    expect($schema['properties'])->toHaveKeys(['schema_version', 'assessed_at', 'facts', 'season_evidence', 'capabilities'])
        ->and($schema['properties']['review']['properties'])->toHaveKeys(['summary', 'context_summary', 'reviewed_at'])
        ->and($spec['components']['schemas']['GameResearchFinding']['properties'])->toHaveKeys(['claim', 'team_side', 'source_url']);
});
