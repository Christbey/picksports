<?php

return [
    'sports' => [
        'nba' => [
            'label' => 'NBA',
            'game_model' => \App\Models\NBA\Game::class,
            'scoreboard_job' => \App\Jobs\ESPN\NBA\FetchGamesFromScoreboard::class,
            'detail_job' => \App\Jobs\ESPN\NBA\FetchGameDetails::class,
            'season_start_month' => 10,
            'season_end_month' => 6,
        ],
        'wnba' => [
            'label' => 'WNBA',
            'game_model' => \App\Models\WNBA\Game::class,
            'scoreboard_job' => \App\Jobs\ESPN\WNBA\FetchGamesFromScoreboard::class,
            'detail_job' => null,
            'season_start_month' => 5,
            'season_end_month' => 9,
        ],
        'cbb' => [
            'label' => 'CBB',
            'game_model' => \App\Models\CBB\Game::class,
            'scoreboard_job' => \App\Jobs\ESPN\CBB\FetchGamesFromScoreboard::class,
            'detail_job' => \App\Jobs\ESPN\CBB\FetchGameDetails::class,
            'season_start_month' => 11,
            'season_end_month' => 4,
        ],
        'wcbb' => [
            'label' => 'WCBB',
            'game_model' => \App\Models\WCBB\Game::class,
            'scoreboard_job' => \App\Jobs\ESPN\WCBB\FetchGamesFromScoreboard::class,
            'detail_job' => \App\Jobs\ESPN\WCBB\FetchGameDetails::class,
            'season_start_month' => 11,
            'season_end_month' => 4,
        ],
        'nfl' => [
            'label' => 'NFL',
            'game_model' => \App\Models\NFL\Game::class,
            'scoreboard_job' => \App\Jobs\ESPN\NFL\FetchGamesFromScoreboard::class,
            'detail_job' => \App\Jobs\ESPN\NFL\FetchGameDetails::class,
            'season_start_month' => 8,
            'season_end_month' => 2,
        ],
        'cfb' => [
            'label' => 'CFB',
            'game_model' => \App\Models\CFB\Game::class,
            'scoreboard_job' => \App\Jobs\ESPN\CFB\FetchGamesFromScoreboard::class,
            'detail_job' => \App\Jobs\ESPN\CFB\FetchGameDetails::class,
            'season_start_month' => 8,
            'season_end_month' => 1,
        ],
    ],
];
