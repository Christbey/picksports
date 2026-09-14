<?php

use App\Models\AiGeneration;

it('fails orphaned running generations without touching active ones', function () {
    $stale = AiGeneration::factory()->create([
        'status' => 'running',
        'started_at' => now()->subMinutes(30),
        'completed_at' => null,
        'metadata' => ['search_cap' => 5],
    ]);
    $active = AiGeneration::factory()->create([
        'status' => 'running',
        'started_at' => now()->subMinutes(2),
        'completed_at' => null,
    ]);

    $this->artisan('ai:reconcile-stale-generations --minutes=15')
        ->expectsOutput('Reconciled 1 stale AI generation(s).')
        ->assertSuccessful();

    expect($stale->fresh())
        ->status->toBe('failed')
        ->error_code->toBe('orphaned_process_timeout')
        ->completed_at->not->toBeNull()
        ->and($stale->fresh()->metadata)
        ->toMatchArray([
            'search_cap' => 5,
            'reconciled_by' => 'ai:reconcile-stale-generations',
            'stale_after_minutes' => 15,
        ])
        ->and($active->fresh()->status)->toBe('running');
});
