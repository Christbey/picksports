<?php

return [
    'canonical_pipeline' => [
        'cbb' => (bool) env('PREDICTION_LIFECYCLE_CBB_CANONICAL_PIPELINE', false),
        'cfb' => (bool) env('PREDICTION_LIFECYCLE_CFB_CANONICAL_PIPELINE', false),
        'mlb' => (bool) env('PREDICTION_LIFECYCLE_MLB_CANONICAL_PIPELINE', false),
        'nba' => (bool) env('PREDICTION_LIFECYCLE_NBA_CANONICAL_PIPELINE', false),
        'nfl' => (bool) env('PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE', false),
        'wcbb' => (bool) env('PREDICTION_LIFECYCLE_WCBB_CANONICAL_PIPELINE', false),
        'wnba' => (bool) env('PREDICTION_LIFECYCLE_WNBA_CANONICAL_PIPELINE', false),
    ],
    'canonical_reads' => [
        'cbb' => (bool) env('PREDICTION_LIFECYCLE_CBB_CANONICAL_READS', false),
        'cfb' => (bool) env('PREDICTION_LIFECYCLE_CFB_CANONICAL_READS', false),
        'mlb' => (bool) env('PREDICTION_LIFECYCLE_MLB_CANONICAL_READS', false),
        'nba' => (bool) env('PREDICTION_LIFECYCLE_NBA_CANONICAL_READS', false),
        'nfl' => (bool) env('PREDICTION_LIFECYCLE_NFL_CANONICAL_READS', false),
        'wcbb' => (bool) env('PREDICTION_LIFECYCLE_WCBB_CANONICAL_READS', false),
        'wnba' => (bool) env('PREDICTION_LIFECYCLE_WNBA_CANONICAL_READS', false),
    ],
];
