<?php

use App\Application\Sports\ReadModels\GameSummary;
use App\Application\Sports\ReadModels\GameSummaryMapper;
use App\Application\Sports\ReadModels\MarketSummary;
use App\Application\Sports\ReadModels\TeamSummary;
use App\Http\Presenters\PublicGameSummaryPresenter;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

it('serves api and inertia game summaries from the shared readonly read model', function () {
    $homeTeam = Team::factory()->create([
        'location' => 'Boston',
        'name' => 'Celtics',
        'abbreviation' => 'BOS',
    ]);
    $awayTeam = Team::factory()->create([
        'location' => 'Los Angeles',
        'name' => 'Lakers',
        'abbreviation' => 'LAL',
    ]);
    $sportEvent = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2026]);
    $game = Game::factory()->create([
        'sport_event_id' => $sportEvent->id,
        'season' => 2026,
        'status' => config('nba.statuses.scheduled'),
        'game_date' => now()->addDay(),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1612.5,
        'away_elo' => 1560.2,
        'home_off_eff' => 118.4,
        'home_def_eff' => 109.1,
        'away_off_eff' => 112.0,
        'away_def_eff' => 111.9,
        'predicted_spread' => -6.5,
        'predicted_total' => 228.5,
        'win_probability' => 0.67,
        'confidence_score' => 74.2,
    ]);

    $context = app(SportContextResolver::class)->resolve('nba');
    $summary = app(SportGameQuery::class)->featuredPredictionSummaries($context)->first();

    expect($summary)->toBeInstanceOf(GameSummary::class)
        ->and($summary->sportEventId)->toBe($sportEvent->public_id)
        ->and($summary->homeTeam)->toBeInstanceOf(TeamSummary::class)
        ->and((new ReflectionClass($summary))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($summary->homeTeam))->isReadOnly())->toBeTrue()
        ->and($summary->prediction?->id)->toBe($prediction->id)
        ->and($summary->prediction?->markets)->toHaveCount(4)
        ->and($summary->prediction?->market('moneyline', 'home'))->toBeInstanceOf(MarketSummary::class)
        ->and($summary->prediction?->market('moneyline', 'home')?->probability)->toBe(0.67)
        ->and($summary->prediction?->market('moneyline', 'away')?->probability)->toBe(0.33)
        ->and($summary->prediction?->market('spread', 'home')?->projectedLine)->toBe(-6.5)
        ->and($summary->prediction?->market('total', 'combined')?->projectedLine)->toBe(228.5)
        ->and((new ReflectionClass($summary->prediction?->markets[0]))->isReadOnly())->toBeTrue();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/nba/games?season=2026')
        ->assertOk()
        ->assertJsonPath('data.0.id', $summary->id)
        ->assertJsonPath('data.0.sport_event_id', $sportEvent->public_id)
        ->assertJsonPath('data.0.game_date', $summary->gameDate)
        ->assertJsonPath('data.0.home_team.display_name', $summary->homeTeam->displayName)
        ->assertJsonPath('data.0.away_team.display_name', $summary->awayTeam?->displayName);

    $this->withoutVite();

    $this->get('/nba')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredPredictions.0.game_id', $summary->id)
            ->where('featuredPredictions.0.game_date', $summary->gameDate)
            ->where('featuredPredictions.0.home_team_abbreviation', $summary->homeTeam->abbreviation)
            ->where('featuredPredictions.0.away_team_abbreviation', $summary->awayTeam?->abbreviation)
        );
});

it('maps an eagerly loaded game summary without issuing serialization queries', function () {
    $game = Game::factory()->create([
        'home_team_id' => Team::factory(),
        'away_team_id' => Team::factory(),
    ]);
    Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1600,
        'away_elo' => 1550,
        'home_off_eff' => 115,
        'home_def_eff' => 110,
        'away_off_eff' => 111,
        'away_def_eff' => 112,
        'predicted_spread' => -4.5,
        'predicted_total' => 224.5,
        'win_probability' => 0.62,
        'confidence_score' => 71,
    ]);

    $loadedGame = Game::query()
        ->with(['homeTeam', 'awayTeam', 'prediction', 'sportEvent'])
        ->findOrFail($game->id);
    $context = app(SportContextResolver::class)->resolve('nba');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $summary = app(GameSummaryMapper::class)->fromModel($context, $loadedGame);
    $payload = app(PublicGameSummaryPresenter::class)->featuredPrediction($summary);

    expect(DB::getQueryLog())->toBe([])
        ->and($payload['predicted_spread'])->toBe(-4.5)
        ->and($payload['home_win_probability'])->toBe(62.0);

    DB::disableQueryLog();
});
