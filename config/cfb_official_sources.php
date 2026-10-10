<?php

// Reviewed school-published records. Content changes require a fresh review, never an inferred correction.
return ['boxscores' => [
    '401866408' => [
        'season' => 2026, 'date' => '2026-08-29', 'home' => 'Eastern Michigan', 'away' => 'Sacramento State',
        'home_score' => 28, 'away_score' => 17,
        'url' => 'https://static.emueagles.com/custompages/football/2026/emufb01.htm',
        'sha256' => 'f46b4d8e311817870e906dd8e07dd2e19742fbf830097dc0275c0e13f7c86c04',
        'content_type' => 'text/html', 'reference' => 'Team Statistics (Final); Individual Statistics (Final)',
        'stats' => ['home' => ['totalYards' => 348, 'netPassingYards' => 279, 'rushingYards' => 69],
            'away' => ['totalYards' => 271, 'netPassingYards' => 222, 'rushingYards' => 49]],
    ],
    '401858201' => [
        'season' => 2026, 'date' => '2026-08-29', 'home' => 'Stanford', 'away' => 'Hawaii',
        'home_score' => 37, 'away_score' => 27,
        'url' => 'https://storage.googleapis.com/stanford-prod/2026/08/30/PfFz9j1FULnUZuLqN6884D5Loc31UV74c7V89ewJ.pdf',
        'sha256' => '3d5b054b7bf21539a170743253dcb1ed01ab00a7624ef59acfb3bc334e04534d',
        'content_type' => 'application/pdf', 'reference' => 'Pages 1-3: final score, player totals and full-game team statistics',
        'stats' => ['home' => ['totalYards' => 468, 'netPassingYards' => 310, 'rushingYards' => 158],
            'away' => ['totalYards' => 325, 'netPassingYards' => 279, 'rushingYards' => 46]],
    ],
], 'play_tails' => [
    '401858234' => [
        'season' => 2026, 'date' => '2026-09-25', 'home' => 'California', 'away' => 'Clemson',
        'home_score' => 10, 'away_score' => 24,
        'url' => 'https://dxbhsrqyrr690.cloudfront.net/sidearm.nextgen.sites/calberk.sidearmsports.com/stats/football/2026/pdf/20260930121023-46251.pdf',
        'sha256' => 'bd05ecc19c20ac6d9b6c6a22ce7c894fa52787ed728751fa31438f5eb0cd0cfc',
        'content_type' => 'application/pdf', 'reference' => 'Pages 10-11: closing Clemson possession and final score',
        'anchor' => ['id' => '401858234709', 'period' => 4, 'clock' => '1:54', 'type' => 'Interception'],
        'offense' => 'away', 'physical_plays' => 5, 'net_yards' => 7,
        // Clock values below are the observed snap/timeout times, not inferred end times.
        'rows' => [
            ['clock' => '1:54', 'type' => 'Timeout', 'text' => 'Timeout Other, clock 01:54.'],
            ['clock' => '1:51', 'type' => 'Rush', 'text' => 'Johnson Jr.,Chris rush middle for 7 yards gain to the CLEM47.', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 60, 'yards' => 7, 'end_down' => 2, 'end_distance' => 3, 'end_yards_to_endzone' => 53],
            ['clock' => '1:48', 'type' => 'Timeout', 'text' => 'Timeout California, clock 01:48.'],
            ['clock' => '1:46', 'type' => 'Pass Reception', 'text' => 'Reynolds,Tait pass complete short right to Preston,Juju for 2 yards loss to the CLEM45.', 'down' => 2, 'distance' => 3, 'yards_to_endzone' => 53, 'yards' => -2, 'end_down' => 3, 'end_distance' => 5, 'end_yards_to_endzone' => 55],
            ['clock' => '1:43', 'type' => 'Timeout', 'text' => 'Timeout California, clock 01:43.'],
            ['clock' => '1:43', 'type' => 'Rush', 'text' => 'Reynolds,Tait rush right for 6 yards gain to the CAL49, 1ST DOWN.', 'down' => 3, 'distance' => 5, 'yards_to_endzone' => 55, 'yards' => 6, 'end_down' => 1, 'end_distance' => 10, 'end_yards_to_endzone' => 49],
            ['clock' => '1:05', 'type' => 'Timeout', 'text' => 'Timeout Clemson, clock 01:05.'],
            ['clock' => '1:02', 'type' => 'Kneel Down', 'text' => 'Kneel down by Reynolds,Tait at CLEM49 for loss of 2 yards.', 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 49, 'yards' => -2, 'end_down' => 2, 'end_distance' => 12, 'end_yards_to_endzone' => 51],
            ['clock' => '0:27', 'type' => 'Kneel Down', 'text' => 'Kneel down by Reynolds,Tait at CLEM47 for loss of 2 yards.', 'down' => 2, 'distance' => 12, 'yards_to_endzone' => 51, 'yards' => -2, 'end_down' => 3, 'end_distance' => 14, 'end_yards_to_endzone' => 53],
            ['clock' => '0:00', 'type' => 'End of Game', 'text' => 'End of game, clock 00:00.'],
        ],
    ],
]];
