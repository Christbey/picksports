<?php

use App\Http\Resources\DashboardPredictionResource;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\NBA\Prediction;
use App\Models\User;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ObservedCfbGameFixture;

it('keeps audited non MLB UTC game starts and same day cutoffs independent of application display timezone', function (string $sport, string $teamClass, string $gameClass) {
    config(['app.timezone' => 'America/Chicago']);
    Sanctum::actingAs(User::factory()->create());
    $home = $teamClass::factory()->create();
    $away = $teamClass::factory()->create();
    $base = ['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'season_type' => '2', 'status' => $sport === 'cfb' ? 'STATUS_SCHEDULED' : 'STATUS_FINAL', 'game_date' => '2026-10-10'];
    $before = $gameClass::factory()->create([...$base, 'game_time' => '22:30:00']);
    $target = $gameClass::factory()->create([...$base, 'game_time' => '23:00:00']);
    $after = $gameClass::factory()->create([...$base, 'game_time' => '23:30:00']);
    if ($sport === 'cfb') {
        foreach ([$before, $target, $after] as $game) {
            ObservedCfbGameFixture::observe($game, '2026-10-10T'.$game->game_time.'Z');
            $game->update(['status' => 'STATUS_FINAL']);
        }
    }
    $query = http_build_query(['season' => 2026, 'before_game_at' => '2026-10-10T23:00:00Z', 'exclude_game_id' => $target->id]);
    $response = $this->getJson("/api/v2/sports/{$sport}/teams/{$home->id}/games?{$query}")->assertOk();
    expect($response->json('data.*.id'))->toBe([$before->id]);
    $detail = $this->getJson("/api/v2/sports/{$sport}/games/{$target->id}")->assertOk();
    expect($detail->json('data.starts_at'))->toBe('2026-10-10T23:00:00+00:00');
})->with([
    ['cfb', Team::class, Game::class],
    ['wnba', App\Models\WNBA\Team::class, App\Models\WNBA\Game::class],
    ['nba', App\Models\NBA\Team::class, App\Models\NBA\Game::class],
    ['cbb', App\Models\CBB\Team::class, App\Models\CBB\Game::class],
    ['wcbb', App\Models\WCBB\Team::class, App\Models\WCBB\Game::class],
]);

it('does not derive UTC starts from malformed or absent stored clocks', function () {
    foreach (['29:77:00', '18:00:00 trailing', '', null] as $clock) {
        expect(GameDateTimePresenter::utcStoredStartsAt('cfb', '2026-10-10', $clock))->toBeNull();
    }
    expect(GameDateTimePresenter::utcStoredStartsAt('cfb', '2026-02-31', '18:00:00'))->toBeNull();
    expect(GameDateTimePresenter::utcStoredStartsAt('mlb', '2026-10-10', '18:00:00'))->toBeNull();
});

it('filters both completed statuses before paginating historical team evidence', function () {
    Sanctum::actingAs(User::factory()->create());
    $home = App\Models\NBA\Team::factory()->create();
    $away = App\Models\NBA\Team::factory()->create();
    $base = ['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'season_type' => '2', 'game_time' => '18:00:00'];
    $first = App\Models\NBA\Game::factory()->create([...$base, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-01']);
    $second = App\Models\NBA\Game::factory()->create([...$base, 'status' => 'STATUS_FULL_TIME', 'game_date' => '2026-10-02']);
    App\Models\NBA\Game::factory()->count(7)->create([...$base, 'status' => 'STATUS_UNRESOLVED', 'game_date' => '2026-10-03']);
    $query = http_build_query(['completed_only' => true, 'season' => 2026, 'before_game_at' => '2026-10-10T23:00:00Z', 'per_page' => 5]);
    $response = $this->getJson("/api/v2/sports/nba/teams/{$home->id}/games?{$query}")->assertOk();
    expect($response->json('data.*.id'))->toBe([$second->id, $first->id]);
    expect($response->json('meta.pagination.total'))->toBe(2);
});

it('preserves null legacy dashboard markets instead of manufacturing real zero estimates', function () {
    $game = App\Models\NBA\Game::factory()->create(['home_team_id' => App\Models\NBA\Team::factory()->create()->id, 'away_team_id' => App\Models\NBA\Team::factory()->create()->id]);
    $prediction = Prediction::query()->create(['game_id' => $game->id, 'win_probability' => null, 'predicted_spread' => null, 'predicted_total' => null]);
    $request = Request::create('/synthetic-dashboard-contract');
    $payload = (new DashboardPredictionResource($prediction))->sport('NBA')->resolve($request);
    expect($payload['win_probability'])->toBeNull();
    expect($payload['predicted_spread'])->toBeNull();
    expect($payload['predicted_total'])->toBeNull();
    $prediction->forceFill(['win_probability' => 0, 'predicted_spread' => 0, 'predicted_total' => 0])->save();
    $measured = (new DashboardPredictionResource($prediction))->sport('NBA')->resolve($request);
    expect($measured['win_probability'])->toBe(0.0);
    expect($measured['predicted_spread'])->toBe(0.0);
    expect($measured['predicted_total'])->toBe(0.0);
});

it('does not expose a duration-valued TIME column as a fabricated clock or fail the game response', function () {
    Sanctum::actingAs(User::factory()->create());
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-10-10', 'game_time' => '29:30:00']);
    $this->getJson('/api/v2/sports/cfb/games/'.$game->id)->assertOk()->assertJsonPath('data.starts_at', null)->assertJsonPath('data.game_time', null);
    expect($game->fresh()->getRawOriginal('game_time'))->toBe('29:30:00');
});

it('serializes legacy dashboard UTC clocks with an explicit offset instead of viewer-local wall time', function () {
    config(['app.timezone' => 'America/Chicago']);
    $game = App\Models\NBA\Game::factory()->create(['home_team_id' => App\Models\NBA\Team::factory()->create()->id, 'away_team_id' => App\Models\NBA\Team::factory()->create()->id, 'game_date' => '2026-10-10', 'game_time' => '23:00:00']);
    $prediction = Prediction::query()->create(['game_id' => $game->id]);
    $payload = (new DashboardPredictionResource($prediction))->sport('NBA')->resolve(Request::create('/synthetic-dashboard-contract'));
    expect($payload['game_time'])->toBe('2026-10-10T23:00:00+00:00');
});
