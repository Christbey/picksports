<?php

return [
    'enabled' => (bool) env('NFL_RESEARCH_PIPELINE_ENABLED', true),
    'cost_control' => [
        // Rolling 24-hour, estimated admission budgets, not a provider billing guarantee.
        'daily_budget_usd' => env('NFL_RESEARCH_DAILY_BUDGET_USD', 5),
        'game_daily_budget_usd' => env('NFL_RESEARCH_GAME_DAILY_BUDGET_USD', 0.75),
        'game_daily_attempts' => env('NFL_RESEARCH_GAME_DAILY_ATTEMPTS', 8),
        'reservation_usd' => env('NFL_RESEARCH_RESERVATION_USD', 0.15),
        'minimum_interval_minutes' => env('NFL_RESEARCH_MINIMUM_INTERVAL_MINUTES', 15),
        'retry_unchanged_minutes' => env('NFL_RESEARCH_RETRY_UNCHANGED_MINUTES', 360),
        'early_minutes' => env('NFL_RESEARCH_EARLY_FRESHNESS_MINUTES', 1440),
        'standard_minutes' => env('AI_NFL_GAME_CONTEXT_RESEARCH_FRESHNESS_MINUTES', 360),
        'pregame_minutes' => env('NFL_RESEARCH_PREGAME_FRESHNESS_MINUTES', 90),
    ],
    'poll_minutes' => 15,
    'ingestion_max_seconds' => env('NFL_RESEARCH_INGESTION_MAX_SECONDS', 180),
    'max_articles_per_source' => 12,
    'lookback_days' => 14,
    'source_freshness_minutes' => env('NFL_RESEARCH_SOURCE_FRESHNESS_MINUTES', 90),
    'market_freshness_minutes' => env('NFL_RESEARCH_MARKET_FRESHNESS_MINUTES', 60),
    'review_rotation_ttl_minutes' => env('NFL_RESEARCH_REVIEW_ROTATION_TTL_MINUTES', 10080),
    'grading' => [
        'batch_size' => env('NFL_RESEARCH_GRADING_BATCH_SIZE', 50),
        'max_per_run' => env('NFL_RESEARCH_GRADING_MAX_PER_RUN', 250),
        'hard_max_per_run' => env('NFL_RESEARCH_GRADING_HARD_MAX_PER_RUN', 1000),
        'retry_after_minutes' => env('NFL_RESEARCH_GRADING_RETRY_AFTER_MINUTES', 55),
    ],
    'material_revision' => [
        // Tiny model/price movements should refresh the working report without
        // manufacturing a new immutable forecast revision.
        'spread_increment' => env('NFL_RESEARCH_MATERIAL_SPREAD_INCREMENT', 0.5),
        'total_increment' => env('NFL_RESEARCH_MATERIAL_TOTAL_INCREMENT', 0.5),
        'probability_increment' => env('NFL_RESEARCH_MATERIAL_PROBABILITY_INCREMENT', 0.01),
        'price_increment' => env('NFL_RESEARCH_MATERIAL_PRICE_INCREMENT', 10),
    ],
    'teams' => [
        'ARI' => 'www.azcardinals.com', 'ATL' => 'www.atlantafalcons.com',
        'BUF' => 'www.buffalobills.com', 'CAR' => 'www.panthers.com',
        'CHI' => 'www.chicagobears.com', 'CIN' => 'www.bengals.com',
        'DAL' => 'www.dallascowboys.com', 'DEN' => 'www.denverbroncos.com',
        'HOU' => 'www.houstontexans.com', 'KC' => 'www.chiefs.com',
        'LAC' => 'www.chargers.com', 'LAR' => 'www.therams.com',
        'LV' => 'www.raiders.com', 'MIA' => 'www.miamidolphins.com',
        'NE' => 'www.patriots.com', 'NYG' => 'www.giants.com',
        'NYJ' => 'www.newyorkjets.com', 'PIT' => 'www.steelers.com',
        'SEA' => 'www.seahawks.com', 'SF' => 'www.49ers.com',
        'TB' => 'www.buccaneers.com', 'TEN' => 'www.tennesseetitans.com',
        'BAL' => 'www.baltimoreravens.com', 'IND' => 'www.colts.com',
        'CLE' => 'www.clevelandbrowns.com', 'JAX' => 'www.jaguars.com',
        'NO' => 'www.neworleanssaints.com', 'DET' => 'www.detroitlions.com',
        'WAS' => 'www.commanders.com', 'PHI' => 'www.philadelphiaeagles.com',
        'GB' => 'www.packers.com', 'MIN' => 'www.vikings.com',
    ],
];
