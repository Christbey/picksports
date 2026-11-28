<?php

use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

it('preserves stored facts when legacy import entry points receive usable files', function (string $command) {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 20,
        'home_linescores' => ['Q1' => 7, 'Q2' => 3, 'Q3' => 7, 'Q4' => 7],
        'away_linescores' => ['Q1' => 7, 'Q2' => 3, 'Q3' => 7, 'Q4' => 3],
    ]);
    $before = $game->fresh()->getAttributes();
    $directory = sys_get_temp_dir().'/picksports-nfl-import-retirement-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $paths = [];
    try {
        if ($command === 'nfl:import-csv-data') {
            $arguments = [];
            foreach (['teams', 'games', 'players', 'plays', 'player-stats', 'team-stats', 'predictions'] as $option) {
                $path = $directory.'/'.$option.'.csv';
                file_put_contents($path, '');
                $paths[] = $path;
                $arguments['--'.$option] = $path;
            }
        } else {
            $path = $directory.'/source.json';
            $paths[] = $path;
            $row = ['espn_event_id' => $game->espn_event_id, 'game_id' => $game->id,
                'home_team_id' => $home->id, 'away_team_id' => $away->id,
                'game_date' => $game->game_date->toDateString(), 'season' => $game->season,
                'season_type' => '2', 'game_week' => $game->week, 'game_status' => 'Final'];
            // Missing scores/line-score observations must never erase known facts.
            file_put_contents($path, json_encode([$row], JSON_THROW_ON_ERROR));
            $arguments = ['file' => $path];
        }
        $inputHashes = array_map(fn (string $path): string => hash_file('sha256', $path), $paths);
        $exit = Artisan::call($command, $arguments);
        expect($game->fresh()?->getAttributes())->toBe($before)
            ->and($exit)->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain($command.' is retired.');
        expect(array_map(fn (string $path): string => hash_file('sha256', $path), $paths))->toBe($inputHashes);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with(['nfl:import-csv-data', 'nfl:import-games-from-json', 'nfl:import-line-scores']);

it('preserves full player play and team observations when the legacy player import receives empty datasets', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $player = Player::factory()->create(['team_id' => $home->id]);
    $play = Play::factory()->create(['game_id' => $game->id]);
    $playerStat = PlayerStat::create(['game_id' => $game->id, 'player_id' => $player->id, 'team_id' => $home->id, 'passing_yards' => 120]);
    $teamStat = TeamStat::factory()->create(['game_id' => $game->id, 'team_id' => $home->id]);
    $models = [$player, $play, $playerStat, $teamStat];
    $before = array_map(fn ($model): array => $model->fresh()->getAttributes(), $models);
    $directory = sys_get_temp_dir().'/picksports-player-import-retirement-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $paths = [];
    try {
        $arguments = [];
        foreach (['player-data', 'player-stats', 'player-season-stats', 'plays', 'team-stats'] as $option) {
            $path = $directory.'/'.$option.'.json';
            file_put_contents($path, '[]');
            $paths[] = $path;
            $arguments['--'.$option] = $path;
        }
        $exit = Artisan::call('nfl:import-player-data-json', $arguments);
        expect(array_map(fn ($model): ?array => $model->fresh()?->getAttributes(), $models))->toBe($before)
            ->and($exit)->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('nfl:import-player-data-json is retired.');
        foreach ($paths as $path) {
            expect(file_get_contents($path))->toBe('[]');
        }
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
