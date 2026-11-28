<?php

use App\Actions\ESPN\CFB\SyncPlayerStats;
use App\Actions\ESPN\MLB\SyncTeamStats;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\MLB\TeamStat;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\ESPN\FootballTeamStatParser;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Fixtures/cfb_complete_box.php';

function nonBasketballStatFixture(string $sport): array
{
    $prefix = 'App\\Models\\'.$sport.'\\';
    $teams = $prefix.'Team';
    $games = $prefix.'Game';
    $players = $prefix.'Player';
    $stats = $prefix.'PlayerStat';
    $home = $teams::factory()->create(['espn_id' => '101']);
    $away = $teams::factory()->create(['espn_id' => '102']);
    $game = $games::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $sections = [];
    foreach ([$home, $away] as $i => $team) {
        $player = $players::factory()->create(['espn_id' => (string) (201 + $i), 'team_id' => $team->id]);
        $stats::create(['game_id' => $game->id, 'team_id' => $team->id, 'player_id' => $player->id,
            ...($sport === 'MLB' ? ['stat_type' => 'batting', 'at_bats' => 4, 'hits' => 2, 'runs' => 1] : ['passing_yards' => 100, 'passing_touchdowns' => 1])]);
        $sections[] = ['team' => ['id' => $team->espn_id], 'statistics' => [[
            ...($sport === 'MLB' ? ['type' => 'batting', 'labels' => ['AB', 'H', 'R'], 'athletes' => []]
                : ['name' => 'passing', 'labels' => ['C/ATT', 'YDS', 'TD'], 'athletes' => []]),
            'athletes' => [['athlete' => ['id' => $player->espn_id], 'stats' => $sport === 'MLB' ? ['4', '2', '1'] : ['10/20', '100', '1']]],
        ]]];
    }

    return [$game, $stats, ['boxscore' => ['players' => $sections]], app('App\\Actions\\ESPN\\'.$sport.'\\SyncPlayerStats')];
}

it('preserves prior NFL observations when numeric cells or completion pairs are malformed', function ($cell, $value) {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture('NFL');
    $before = $stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    $payload['boxscore']['players'][1]['statistics'][0]['athletes'][0]['stats'][$cell] = $value;
    expect($action->execute($payload, $game))->toBe(0)
        ->and($stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with([[1, ''], [2, '--'], [0, '/'], [0, '21/20']]);

it('does not erase known observations with unlabeled truncated duplicate or unsupported categories', function ($sport, $defect) {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture($sport);
    $before = $stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    $category = &$payload['boxscore']['players'][1]['statistics'][0];
    match ($defect) {
        'unlabeled' => $category['labels'] = [],
        'truncated' => array_pop($category['athletes'][0]['stats']),
        'duplicate' => $category['labels'][1] = $category['labels'][0],
        'unsupported' => $category[$sport === 'MLB' ? 'type' : 'name'] = 'unknown_category',
    };
    expect($action->execute($payload, $game))->toBe(0)
        ->and($stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with(['NFL', 'MLB'])->with(['unlabeled', 'truncated', 'duplicate', 'unsupported']);

it('does not manufacture unlabeled MLB runs from another labeled numeric metric', function () {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture('MLB');
    $stats::where('game_id', $game->id)->delete();
    foreach ($payload['boxscore']['players'] as &$section) {
        $section['statistics'][0]['labels'] = ['AB', 'H'];
        $section['statistics'][0]['athletes'][0]['stats'] = ['3', '1'];
    }
    unset($section);
    expect($action->execute($payload, $game))->toBe(2);
    foreach ($stats::where('game_id', $game->id)->get() as $stat) {
        expect($stat->at_bats)->toBe(3)->and($stat->hits)->toBe(1)->and($stat->runs)->toBeNull();
    }
});

it('keeps known metrics when a sparse replacement omits them', function ($sport) {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture($sport);
    $before = $stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    foreach ($payload['boxscore']['players'] as &$section) {
        array_pop($section['statistics'][0]['labels']);
        array_pop($section['statistics'][0]['athletes'][0]['stats']);
    }
    unset($section);
    expect($action->execute($payload, $game))->toBe(0)
        ->and($stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with(['NFL', 'MLB']);

it('does not certify blank CFB touchdown evidence merely because yards reconcile', function () {
    Storage::fake('local');
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $payload = cfbCompleteBox($game);
    $category = &$payload['boxscore']['players'][0]['statistics'][0];
    $category['labels'][] = 'TD';
    $category['athletes'][0]['stats'][] = '';
    expect(app(CfbGameDataValidator::class)->boxscore($payload, $game)['state'])->toBe('partial');
});

it('rejects nonintegral nonfinite or unlabeled MLB observations even for an initial import', function ($defect) {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture('MLB');
    $stats::where('game_id', $game->id)->delete();
    foreach ($payload['boxscore']['players'] as &$team) {
        $category = &$team['statistics'][0];
        match ($defect) {
            'fractional count' => $category['athletes'][0]['stats'][0] = '1.5',
            'negative count' => $category['athletes'][0]['stats'][0] = '-1',
            'missing type' => $category['type'] = '',
            'invalid innings' => $category = ['type' => 'pitching', 'labels' => ['IP'], 'athletes' => [['athlete' => $category['athletes'][0]['athlete'], 'stats' => ['5.4']]]],
            'nonfinite rate' => $category = ['type' => 'pitching', 'labels' => ['ERA'], 'athletes' => [['athlete' => $category['athletes'][0]['athlete'], 'stats' => ['1e309']]]],
        };
    }
    unset($team, $category);
    expect($action->execute($payload, $game))->toBe(0)->and($stats::where('game_id', $game->id)->count())->toBe(0);
})->with(['fractional count', 'negative count', 'missing type', 'invalid innings', 'nonfinite rate']);

it('does not store a pitch-count heuristic as an observed MLB pitchers-used value', function () {
    [$game] = nonBasketballStatFixture('MLB');
    $team = App\Models\MLB\Team::findOrFail($game->home_team_id);
    $payload = ['boxscore' => ['teams' => [['team' => ['id' => $team->espn_id], 'statistics' => [[
        'name' => 'pitching', 'stats' => [['name' => 'pitches', 'displayValue' => '150']],
    ]]]]]];
    expect(app(SyncTeamStats::class)->execute($payload, $game))->toBe(1)
        ->and(TeamStat::where('game_id', $game->id)->sole()->pitchers_used)->toBeNull();
});

it('preserves known team facts when replacement drops them', function ($sport) {
    [$game] = nonBasketballStatFixture($sport);
    $prefix = 'App\\Models\\'.$sport.'\\';
    $teamClass = $prefix.'Team';
    $statsClass = $prefix.'TeamStat';
    $old = $statsClass::create(['game_id' => $game->id, 'team_id' => $game->home_team_id, 'team_type' => 'home',
        ...($sport === 'NFL' ? ['total_yards' => 100, 'passing_yards' => 75] : ['runs' => 2, 'hits' => 4])]);
    $before = $old->fresh()->getAttributes();
    $data = $sport === 'NFL' ? [['name' => 'totalYards', 'displayValue' => '200']]
        : [['name' => 'batting', 'stats' => [['name' => 'runs', 'displayValue' => '3']]]];
    $payload = ['boxscore' => ['teams' => [['team' => ['id' => $teamClass::findOrFail($game->home_team_id)->espn_id], 'statistics' => $data]]]];
    expect(app('App\\Actions\\ESPN\\'.$sport.'\\SyncTeamStats')->execute($payload, $game))->toBe(0)
        ->and($old->fresh()?->getAttributes())->toBe($before);
})->with(['NFL', 'MLB']);

it('validates NFL and MLB replacement identity against the locked current game', function ($sport) {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture($sport);
    $prefix = 'App\\Models\\'.$sport.'\\';
    $teams = $prefix.'Team';
    $replacement = $teams::factory()->create();
    $game::whereKey($game->id)->update(['away_team_id' => $replacement->id]);
    $before = $stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    expect($action->execute($payload, $game))->toBe(0)
        ->and($stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with(['NFL', 'MLB']);

it('retains explicit zero and signed NFL yard observations without guessing values', function () {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture('NFL');
    foreach ($payload['boxscore']['players'] as &$team) {
        $team['statistics'][0]['athletes'][0]['stats'] = ['0/0', '-3', '0'];
    }
    unset($team);
    expect($action->execute($payload, $game))->toBe(2);
    foreach ($stats::where('game_id', $game->id)->get() as $stat) {
        expect($stat->passing_yards)->toBe(-3)->and($stat->passing_touchdowns)->toBe(0)->and($stat->passing_attempts)->toBe(0);
    }
});

it('keeps signed NCAA team credits separate from individual player observations', function () {
    Storage::fake('local');
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $payload = cfbCompleteBox($game);
    expect(app(SyncPlayerStats::class)->execute($payload, $game))->toBe(2)
        ->and(PlayerStat::where('game_id', $game->id)->count())->toBe(2)
        ->and(PlayerStat::where('game_id', $game->id)->first()->rushing_yards)->toBe(244)
        ->and(data_get(GameDataCheck::where('game_id', $game->id)->first()->evidence, 'team_attributed_stats.'.$game->homeTeam->espn_id.'.rushing.0.YDS'))->toBe('-31');
});

it('refuses conflicting duplicate football athlete category observations', function () {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture('NFL');
    $before = $stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all();
    $duplicate = $payload['boxscore']['players'][0]['statistics'][0];
    $duplicate['athletes'][0]['stats'][1] = '999';
    $payload['boxscore']['players'][0]['statistics'][] = $duplicate;
    expect($action->execute($payload, $game))->toBe(0)
        ->and($stats::where('game_id', $game->id)->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

it('rejects conflicting CFB categories before promoting a reconciled first category', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $payload = cfbCompleteBox($game);
    $duplicate = $payload['boxscore']['players'][0]['statistics'][0];
    $duplicate['athletes'][0]['stats'][1] = '301';
    $payload['boxscore']['players'][0]['statistics'][] = $duplicate;
    expect(app(CfbGameDataValidator::class)->boxscore($payload, $game)['state'])->toBe('partial');
});

it('refuses initial MLB cross-team attribution of the same athlete and type', function () {
    [$game, $stats, $payload, $action] = nonBasketballStatFixture('MLB');
    $stats::where('game_id', $game->id)->delete();
    $payload['boxscore']['players'][1]['statistics'][0]['athletes'][0]['athlete']['id'] = $payload['boxscore']['players'][0]['statistics'][0]['athletes'][0]['athlete']['id'];
    expect($action->execute($payload, $game))->toBe(0)->and($stats::where('game_id', $game->id)->count())->toBe(0);
});

it('rejects contradictory canonical aliases rather than letting order choose an observation', function ($kind) {
    if ($kind === 'football team') {
        expect(fn () => app(FootballTeamStatParser::class)->parse([
            ['name' => 'redZoneScores', 'displayValue' => '1'], ['name' => 'redZoneEfficiency', 'displayValue' => '2-3'],
        ]))->toThrow(DomainException::class);
    } else {
        [$game, $stats, $payload, $action] = nonBasketballStatFixture('MLB');
        foreach ($payload['boxscore']['players'] as &$team) {
            $team['statistics'][0]['labels'] = ['AB', 'H', 'hits', 'R'];
            $team['statistics'][0]['athletes'][0]['stats'] = ['4', '2', '3', '1'];
        }
        unset($team);
        expect($action->execute($payload, $game))->toBe(0);
    }
})->with(['football team', 'MLB player']);

it('does not certify duplicate CFB coverage that is summed differently from persisted athlete rows', function ($duplicateCategory) {
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $payload = cfbCompleteBox($game);
    $duplicate = $payload['boxscore']['players'][0]['statistics'][0]['athletes'][0];
    if ($duplicateCategory) {
        $duplicate['athlete']['id'] = '90003';
        $duplicate['stats'][1] = '100';
        $payload['boxscore']['players'][0]['statistics'][] = ['name' => 'passing', 'labels' => ['C/ATT', 'YDS'], 'athletes' => [$duplicate]];
    } else {
        $payload['boxscore']['players'][0]['statistics'][0]['athletes'][] = $duplicate;
        $payload['boxscore']['teams'][0]['statistics'][0]['displayValue'] = '813';
        $payload['boxscore']['teams'][0]['statistics'][1]['displayValue'] = '600';
    }
    expect(app(CfbGameDataValidator::class)->boxscore($payload, $game)['state'])->toBe('partial');
})->with([false, true]);
