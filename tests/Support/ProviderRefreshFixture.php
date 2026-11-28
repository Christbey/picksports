<?php

use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Illuminate\Database\Eloquent\Model;

function enableSyntheticRefreshScope(Model $team, string $component, int $season): void
{
    config(['provider_refresh.enabled' => true]);
    $target = app(EloWriteTargetIdentity::class)->describe($team->getConnection())['fingerprint'];
    config(['provider_refresh.write_target_fingerprint' => $target]);
    if (! $team->getConnection()->getSchemaBuilder()->hasTable('provider_refresh_heads')) {
        throw new RuntimeException('Refresh fixture requires the additive ordering schema.');
    }

    $team->getConnection()->table('provider_refresh_heads')->insert([
        'provider' => 'espn', 'sport' => explode('_', $team->getTable())[0], 'component' => $component, 'team_id' => $team->id,
        'season_key' => $season, 'protocol_version' => 1, 'target_fingerprint' => $target,
        'activation_operator' => 'synthetic-test-only', 'activation_reason' => 'Synthetic fixture; no real activation or drain.',
        'drain_reference' => 'synthetic-not-real-drain', 'source_review_reference' => 'synthetic-format-contract-only',
        'activated_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'drained_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s.u'), 'issued_sequence' => 0,
        'latest_terminal_sequence' => 0, 'latest_applied_sequence' => 0,
        'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
    ]);

}
