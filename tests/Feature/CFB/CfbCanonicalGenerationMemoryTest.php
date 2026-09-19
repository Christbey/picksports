<?php

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\SportEvent;

it('releases retained prediction graphs between games even when one generation fails', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach (range(1, 3) as $i) {
        $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addDay()]);
        Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'status' => 'STATUS_SCHEDULED',
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => now()->addDay()->toDateString()]);
    }
    $references = [];
    $calls = 0;
    $this->mock(GenerateCanonicalPrediction::class, function ($mock) use (&$references, &$calls) {
        $mock->shouldReceive('execute')->times(3)->andReturnUsing(function ($game) use (&$references, &$calls) {
            foreach ($references as $reference) {
                expect($reference->get())->toBeNull();
            }
            $graph = new stdClass;
            $graph->parent = $graph;
            $game->setRelation('prediction_graph', $graph);
            $references[] = WeakReference::create($graph);
            if (++$calls === 1) {
                throw new RuntimeException('Synthetic source failure');
            }

            return new CanonicalPrediction;
        });
    });
    $this->artisan('cfb:generate-canonical-predictions', ['--season' => 2026, '--days-forward' => 2])->assertExitCode(1);
    expect($calls)->toBe(3);
    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }
});
