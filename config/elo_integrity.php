<?php

return [
    'enabled' => (bool) env('SPORT_ELO_INTEGRITY_ENABLED', false),
    'protocol_version' => 1,
    // Admission validates actual used PDOs; this does not change any open SQL session.
    'sql_clock' => ['mysql_session_timezone' => '+00:00', 'reconnect' => 'fresh_admission_required'],
    // Reviewed authoritative write-target hashes; empty denies every active publication.
    'write_target_fingerprints' => [],
    'calculation_versions' => [
        'nfl' => 'nfl-elo-v2-candidate', 'nba' => 'nba-elo-v2-candidate', 'mlb' => 'mlb-elo-v2-candidate',
        'cbb' => 'cbb-elo-v2-candidate', 'wcbb' => 'wcbb-elo-v2-candidate',
    ],
];
