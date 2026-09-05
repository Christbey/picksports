<?php

return [
    /*
    | This inventory records internal review state only. Status values are
    | operational metadata and are not conclusions about legal rights.
    */
    'providers' => [
        'espn' => [
            'label' => 'ESPN data feeds',
            'required_for_public_api' => true,
            'status' => env('PROVIDER_LICENSE_ESPN_STATUS', 'unconfirmed'),
            'evidence_reference' => env('PROVIDER_LICENSE_ESPN_EVIDENCE'),
            'reviewed_at' => env('PROVIDER_LICENSE_ESPN_REVIEWED_AT'),
            'owner' => env('PROVIDER_LICENSE_ESPN_OWNER'),
            'notes' => null,
        ],
        'odds_api' => [
            'label' => 'The Odds API',
            'required_for_public_api' => true,
            'status' => env('PROVIDER_LICENSE_ODDS_API_STATUS', 'unconfirmed'),
            'evidence_reference' => env('PROVIDER_LICENSE_ODDS_API_EVIDENCE'),
            'reviewed_at' => env('PROVIDER_LICENSE_ODDS_API_REVIEWED_AT'),
            'owner' => env('PROVIDER_LICENSE_ODDS_API_OWNER'),
            'notes' => null,
        ],
        'collegefootballdata' => [
            'label' => 'CollegeFootballData',
            'required_for_public_api' => true,
            'status' => env('PROVIDER_LICENSE_CFB_DATA_STATUS', 'unconfirmed'),
            'evidence_reference' => env('PROVIDER_LICENSE_CFB_DATA_EVIDENCE'),
            'reviewed_at' => env('PROVIDER_LICENSE_CFB_DATA_REVIEWED_AT'),
            'owner' => env('PROVIDER_LICENSE_CFB_DATA_OWNER'),
            'notes' => null,
        ],
        'nflverse' => [
            'label' => 'nflverse datasets',
            'required_for_public_api' => true,
            'status' => env('PROVIDER_LICENSE_NFLVERSE_STATUS', 'unconfirmed'),
            'evidence_reference' => env('PROVIDER_LICENSE_NFLVERSE_EVIDENCE'),
            'reviewed_at' => env('PROVIDER_LICENSE_NFLVERSE_REVIEWED_AT'),
            'owner' => env('PROVIDER_LICENSE_NFLVERSE_OWNER'),
            'notes' => null,
        ],
        'scores_and_odds' => [
            'label' => 'ScoresAndOdds',
            'required_for_public_api' => true,
            'status' => env('PROVIDER_LICENSE_SCORES_ODDS_STATUS', 'unconfirmed'),
            'evidence_reference' => env('PROVIDER_LICENSE_SCORES_ODDS_EVIDENCE'),
            'reviewed_at' => env('PROVIDER_LICENSE_SCORES_ODDS_REVIEWED_AT'),
            'owner' => env('PROVIDER_LICENSE_SCORES_ODDS_OWNER'),
            'notes' => null,
        ],
        'open_meteo' => [
            'label' => 'Open-Meteo',
            'required_for_public_api' => false,
            'status' => env('PROVIDER_LICENSE_OPEN_METEO_STATUS', 'unconfirmed'),
            'evidence_reference' => env('PROVIDER_LICENSE_OPEN_METEO_EVIDENCE'),
            'reviewed_at' => env('PROVIDER_LICENSE_OPEN_METEO_REVIEWED_AT'),
            'owner' => env('PROVIDER_LICENSE_OPEN_METEO_OWNER'),
            'notes' => 'Tracked because weather-derived fields may be included in future products.',
        ],
    ],
];
