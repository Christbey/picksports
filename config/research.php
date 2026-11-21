<?php

return [
    // Shared research is independently activated; existing NFL switches are unchanged.
    'enabled' => (bool) env('SPORTS_RESEARCH_ENABLED', false),
    'web_discovery_enabled' => false,
    'maximum_document_bytes' => 250000,
    'maximum_documents_per_game' => 24,
    'sports' => [
        'nfl' => ['enabled' => (bool) env('NFL_SHARED_RESEARCH_ENABLED', false), 'topics' => ['availability', 'quarterback', 'personnel', 'weather', 'coaching'], 'freshness_minutes' => 2160],
        'nba' => ['enabled' => (bool) env('NBA_RESEARCH_ENABLED', false), 'topics' => ['availability', 'lineup', 'rotation', 'rest_travel'], 'freshness_minutes' => 120],
        'mlb' => ['enabled' => (bool) env('MLB_RESEARCH_ENABLED', false), 'topics' => ['starting_pitcher', 'lineup', 'bullpen', 'weather'], 'freshness_minutes' => 90],
        'wnba' => ['enabled' => (bool) env('WNBA_RESEARCH_ENABLED', false), 'topics' => ['availability', 'lineup', 'rotation', 'rest_travel'], 'freshness_minutes' => 120],
        'cbb' => ['enabled' => (bool) env('CBB_RESEARCH_ENABLED', false), 'topics' => ['availability', 'rotation', 'venue', 'rest_travel'], 'freshness_minutes' => 180],
        'wcbb' => ['enabled' => (bool) env('WCBB_RESEARCH_ENABLED', false), 'topics' => ['availability', 'rotation', 'venue', 'rest_travel'], 'freshness_minutes' => 180],
        'cfb' => ['enabled' => (bool) env('CFB_RESEARCH_ENABLED', false), 'topics' => ['availability', 'quarterback', 'personnel', 'weather'], 'freshness_minutes' => 180],
    ],
];
