<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Services\NFL\NflSeasonEvidenceWeight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-17 12:00:00 UTC');
});
afterEach(fn () => Carbon::setTestNow());

function seasonAdaptationFixture(int $homeGames, int $awayGames): array
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $opponent = Team::factory()->create();
    $target = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => '2', 'week' => 6, 'game_date' => '2026-10-18',
        'game_time' => '17:00:00', 'status' => 'STATUS_SCHEDULED', 'neutral_site' => true]);
    foreach ([$home->id => $homeGames, $away->id => $awayGames] as $id => $count) {
        for ($i = 0; $i < $count; $i++) {
            Game::factory()->create(['home_team_id' => $id, 'away_team_id' => $opponent->id,
                'season' => 2026, 'season_type' => '2', 'game_date' => Carbon::parse('2026-09-01')->addDays($i),
                'game_time' => '17:00:00', 'status' => 'STATUS_FINAL']);
        }
    }
    $metrics = [];
    foreach ([$home->id => [$homeGames, -4.283], $away->id => [$awayGames, 2.842]] as $id => [$count, $rating]) {
        $metrics[] = TeamMetric::create(['team_id' => $id, 'season' => 2026, 'season_type' => '2',
            'games_played' => $count, 'predictive_rating' => $rating]);
    }

    return [$target, ...$metrics];
}

it('progressively replaces historical preference with completed season evidence', function (int $games, float $expected) {
    [$game, $home, $away] = seasonAdaptationFixture($games, $games);
    $result = app(NflSeasonEvidenceWeight::class)->resolve($game, $home, $away, .25);
    expect($result['weight'])->toEqualWithDelta($expected, .000001)
        ->and($result['effective_games'])->toBe($games)
        ->and($result['calibrated'])->toBeFalse();
})->with([[0, .25], [1, 1 / 3], [2, .5], [4, 2 / 3], [5, 5 / 7], [17, 17 / 19], [20, .9]]);

it('uses the smaller covered sample and does not increase weight during a bye', function () {
    [$game, $home, $away] = seasonAdaptationFixture(5, 4);
    $service = app(NflSeasonEvidenceWeight::class);
    $before = $service->resolve($game, $home, $away, .25);
    $game->week = 7;
    $game->game_date = '2026-10-25';
    expect($service->resolve($game, $home, $away, .25)['weight'])->toBe($before['weight']);
    $away->games_played = 2;
    expect($service->resolve($game, $home, $away, .25)['weight'])->toBe(.5);
});

it('excludes the target future incomplete postseason and other season games', function () {
    [$game, $home, $away] = seasonAdaptationFixture(2, 2);
    foreach ([['season' => 2025], ['season_type' => '1'], ['season_type' => '3'],
        ['status' => 'STATUS_IN_PROGRESS'], ['game_date' => '2026-10-20'],
        ['game_date' => '2026-10-18', 'game_time' => '18:00:00'],
        ['result_updated_at' => '2026-10-19 01:00:00']] as $change) {
        $extra = Game::factory()->create(array_replace(['home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id, 'season' => 2026, 'season_type' => '2',
            'game_date' => '2026-10-10', 'game_time' => '17:00:00', 'status' => 'STATUS_FINAL'], $change));
        if (isset($change['result_updated_at'])) {
            DB::table('nfl_games')->where('id', $extra->id)->update(['result_updated_at' => $change['result_updated_at']]);
        }
    }
    $game->update(['status' => 'STATUS_FINAL']);
    $home->games_played = $away->games_played = 20;
    $result = app(NflSeasonEvidenceWeight::class)->resolve($game, $home, $away, .25);
    expect($result['home_games'])->toBe(2)->and($result['away_games'])->toBe(2)
        ->and($result['weight'])->toBe(.5);
});

it('preserves the fixed policy when explicitly disabled', function () {
    [$game, $home, $away] = seasonAdaptationFixture(5, 5);
    config(['nfl.predictions.preseason_signal.season_adaptation.enabled' => false]);
    expect(app(NflSeasonEvidenceWeight::class)->resolve($game, $home, $away, .25)['weight'])->toBe(.25);
});

function seasonBlendProbe(): GeneratePredictionFromHistoricalElo
{
    return new class extends GeneratePredictionFromHistoricalElo
    {
        public function probe(Game $game): array
        {
            $outputs = $this->applyPreseasonSignalBlend($game, 11.43, .67504);

            return ['margin' => $outputs[0], 'metadata' => $this->lastModelMetadata['preseason_signal']];
        }
    };
}

it('integrates changing season evidence into the actual forecast blend with auditable samples', function () {
    [$game] = seasonAdaptationFixture(5, 4);
    $result = seasonBlendProbe()->probe($game);
    expect($result['margin'])->toEqualWithDelta(-.94, .000001)
        ->and($result['metadata']['season_adaptation']['effective_games'])->toBe(4)
        ->and($result['metadata']['weight'])->toBe(.6667);
    config(['nfl.predictions.preseason_signal.season_adaptation.enabled' => false]);
    expect(seasonBlendProbe()->probe($game)['margin'])->toEqualWithDelta(6.79125, .000001);
});

it('does not use missing or hindsight metrics for the adaptive blend', function () {
    [$game, $home] = seasonAdaptationFixture(5, 4);
    DB::table('nfl_team_metrics')->where('id', $home->id)->update(['updated_at' => '2026-10-19 12:00:00']);
    $result = seasonBlendProbe()->probe($game);
    expect($result['margin'])->toBe(11.43)
        ->and($result['metadata']['reason'])->toBe('metrics_not_available_as_of_kickoff');
    $home->delete();
    expect(seasonBlendProbe()->probe($game)['metadata']['reason'])->toBe('missing_predictive_rating');
});
