<?php

return [
    'capture_enabled' => (bool) env('NFL_GAME_SIMULATION_CAPTURE_ENABLED', false),
    'iterations' => 10000,
    'seed' => 20261024,
    'forecast_max_age_hours' => 36,
    'minimum_history' => 200,
    'history_limit' => 512,
    'result_maturity_hours' => 24,
    // Explicit preview assumptions; these are not fitted/calibrated parameters.
    'provisional_home_sd' => 10.0,
    'provisional_away_sd' => 10.0,
    'provisional_correlation' => 0.15,
];
