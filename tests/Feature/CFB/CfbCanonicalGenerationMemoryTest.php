<?php

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use Illuminate\Support\Facades\Process;

it('isolates game calculations in sequential workers and reports individual worker failures', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $games = [];
    foreach (range(1, 3) as $i) {
        $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addDay()]);
        $games[] = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => now()->addDay()->toDateString()]);
    }
    $this->mock(GenerateCanonicalPrediction::class, fn ($mock) => $mock->shouldNotReceive('execute'));
    Process::fake(fn ($process) => in_array('--game='.$games[0]->id, $process->command, true)
        ? Process::result(errorOutput: 'Source unavailable', exitCode: 1)
        : Process::result(output: 'Canonical CFB generation complete: 1 succeeded, 0 failed.', exitCode: 0));
    $this->artisan('cfb:generate-canonical-predictions', ['--season' => 2026, '--days-forward' => 2, '--draft' => true])
        ->expectsOutput('Canonical CFB generation complete: 2 succeeded, 1 failed.')->assertExitCode(1);
    Process::assertRanTimes(fn () => true, 3);
    foreach ($games as $game) {
        Process::assertRan(fn ($process) => in_array('--game='.$game->id, $process->command, true)
            && in_array('--draft', $process->command, true) && $process->timeout === 120);
    }
});
