<?php

use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Services\Sports\SportsDateWindowService;
use App\Support\CFB\CfbGameStart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('qualifies CFB calendar headers independently while retaining the callers prediction and game correlation', function (string $date, array $expectedKinds) {
    Http::preventStrayRequests();
    config(['sports.business_timezone' => 'America/Chicago']);
    $selected = Team::factory()->create();
    $other = Team::factory()->create();
    $away = Team::factory()->create();
    $ids = [];
    foreach (['unknown', 'known', 'midnight', 'foreign-team', 'foreign-prediction'] as $kind) {
        $event = 'correlated-'.$kind;
        $game = Game::factory()->create(['home_team_id' => $kind === 'foreign-team' ? $other->id : $selected->id,
            'away_team_id' => $away->id, 'espn_event_id' => $event, 'game_date' => '2027-01-13', 'game_time' => $kind === 'midnight' ? '00:00:00' : '02:00:00',
            ...CfbGameStart::attributes($kind === 'midnight' ? '2027-01-13T00:00:00Z' : '2027-01-13T02:00:00Z', ['timeValid' => in_array($kind, ['known', 'midnight'], true)], 'espn_schedule', $event)]);
        $prediction = Prediction::create(['game_id' => $game->id, 'model_version' => $kind === 'foreign-prediction' ? 'other-context' : 'selected-context']);
        $ids[$kind] = $prediction->id;
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $windows = app(SportsDateWindowService::class);
    $window = $windows->forDate($date);
    $rows = Prediction::where('model_version', 'selected-context')->whereHas('game', function ($games) use ($selected, $windows, $window) {
        $games->where('home_team_id', $selected->id);
        $windows->applyGameDateWindow($games, $window);
    })->pluck('id')->all();
    expect($rows)->toEqualCanonicalizing(array_map(fn ($kind) => $ids[$kind], $expectedKinds));
    $headers = array_values(array_filter($queries, fn ($sql) => str_contains(strtolower($sql), 'case when')));
    expect($headers)->not->toBeEmpty();
    foreach ($headers as $sql) {
        expect($sql)->not->toContain('cfb_predictions')->not->toContain('select *');
    }
    Http::assertNothingSent();
})->with([['2027-01-13', ['unknown']], ['2027-01-12', ['known', 'midnight']]]);
