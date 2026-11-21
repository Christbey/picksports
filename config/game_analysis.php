<?php

return [
    // Delivery wiring is independent of research/review capability activation.
    'schedule_enabled' => (bool) env('GAME_ANALYSIS_SCHEDULE_ENABLED', false),
    'publisher_enabled' => (bool) env('GAME_ANALYSIS_PUBLISHER_ENABLED', false),
    'days_forward' => 7,
    'batch_per_sport' => 4,
    'dispatch_rotation_minutes' => 10080,
];
