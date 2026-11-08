<?php

use App\Http\Controllers\Api\V2\NflResearchController;
use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('fetches original and latest research fields without sorting large revision documents', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $first = ResearchRevision::create(['game_id' => $game->id, 'input_hash' => str_repeat('a', 64),
        'baseline' => ['predicted_spread' => 3.5], 'revised' => ['predicted_spread' => 4.5],
        'evidence' => ['large' => str_repeat('x', 1_000_000)], 'brief' => ['material_hash' => 'first'], 'market' => [], 'created_at' => now()]);
    $latest = ResearchRevision::create(['game_id' => $game->id, 'input_hash' => str_repeat('b', 64),
        'baseline' => [], 'revised' => ['predicted_spread' => 5.5],
        'evidence' => ['large' => str_repeat('y', 1_000_000)], 'brief' => ['material_hash' => 'latest'], 'market' => [], 'created_at' => now()]);
    DB::enableQueryLog();
    $baseline = ResearchRevision::baselineForGame($game->id);
    $summary = ResearchRevision::latestSummaryForGame($game->id);
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();
    expect($baseline->id)->toBe($first->id)->and($baseline->baseline)->toBe(['predicted_spread' => 3.5])
        ->and($summary->id)->toBe($latest->id)->and($summary->revised)->toBe(['predicted_spread' => 5.5])
        ->and($summary->brief)->toBe(['material_hash' => 'latest'])
        ->and(array_keys($summary->getAttributes()))->not->toContain('evidence', 'market', 'evaluation', 'baseline')
        ->and($sql)->not->toContain('order by', 'select *');
});

it('returns no research summary when a game has no stored revisions', function () {
    expect(ResearchRevision::baselineForGame(999999))->toBeNull()
        ->and(ResearchRevision::latestSummaryForGame(999999))->toBeNull();
});

it('returns research history in revision order without loading unused evidence or sorting documents', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = ResearchRevision::create(['game_id' => $game->id, 'input_hash' => hash('sha256', (string) $i),
            'baseline' => [], 'revised' => ['predicted_spread' => $i], 'brief' => [], 'market' => [],
            'evidence' => ['large' => str_repeat('x', 1_000_000)], 'created_at' => now()])->id;
    }
    $games = Mockery::mock(SportGameQuery::class);
    $games->shouldReceive('find')->once()->andReturn($game);
    DB::enableQueryLog();
    $response = app(NflResearchController::class)(
        'nfl', (string) $game->id, Request::create('/'),
        app(SportContextResolver::class), $games,
    );
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect(array_column($response->getData(true)['data']['revisions'], 'id'))->toBe(array_reverse($ids));
    foreach ($queries as $query) {
        expect($query)->not->toContain('select *', '"evidence"');
        if (str_contains($query, 'order by')) {
            expect($query)->toStartWith('select "id"');
        }
    }
});
