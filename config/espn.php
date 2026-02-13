<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ESPN API Bases
    |--------------------------------------------------------------------------
    */
    'bases' => [
        'site' => 'https://site.api.espn.com/apis/site/v2/sports/{sport}/{league}',
        'core' => 'https://sports.core.api.espn.com/v2/sports/{sport}/leagues/{league}',
        'cdn' => 'https://cdn.espn.com/core/{leagueShort}',
        'common_athlete' => 'https://site.web.api.espn.com/apis/common/v3/sports/{sport}/{league}/athletes/{athleteId}',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sports / Leagues we support
    |--------------------------------------------------------------------------
    */
    'leagues' => [

        'cfb' => [
            'sport' => 'football',
            'league' => 'college-football',
            'leagueShort' => 'ncaaf',

            'site' => [
                'scoreboard' => '/scoreboard',
                'summary' => '/summary?event={eventId}',
                'teams' => '/teams',
                'team' => '/teams/{teamId}',
                'roster' => '/teams/{teamId}/roster',
                'schedule' => '/teams/{teamId}/schedule',
                'standings' => '/standings',
                'news' => '/news',
                'rankings' => '/rankings',
            ],

            'core' => [
                'seasons' => '/seasons',
                'events' => '/events',
                'weekly_events' => '/seasons/{year}/types/{seasonType}/weeks/{week}/events',
                'plays' => '/events/{eventId}/competitions/{competitionId}/plays?limit=300',
                'probabilities' => '/events/{eventId}/competitions/{competitionId}/probabilities?limit=200',
                'odds' => '/events/{eventId}/competitions/{competitionId}/odds',
                'predictor' => '/events/{eventId}/competitions/{competitionId}/predictor',
            ],
        ],

        'cbb' => [
            'sport' => 'basketball',
            'league' => 'mens-college-basketball',
            'leagueShort' => 'ncaam',

            'site' => [
                'scoreboard' => '/scoreboard',
                'summary' => '/summary?event={eventId}',
                'teams' => '/teams',
                'team' => '/teams/{teamId}',
                'roster' => '/teams/{teamId}/roster',
                'schedule' => '/teams/{teamId}/schedule',
                'standings' => '/standings',
                'news' => '/news',
                'rankings' => '/rankings',
            ],

            'core' => [
                'seasons' => '/seasons',
                'events' => '/events',
                'weekly_events' => '/seasons/{year}/types/{seasonType}/weeks/{week}/events',
                'plays' => '/events/{eventId}/competitions/{competitionId}/plays?limit=300',
                'odds' => '/events/{eventId}/competitions/{competitionId}/odds',
                'predictor' => '/events/{eventId}/competitions/{competitionId}/predictor',
            ],
        ],

        'wcbb' => [
            'sport' => 'basketball',
            'league' => 'womens-college-basketball',
            'leagueShort' => 'ncaaw',

            'site' => [
                'scoreboard' => '/scoreboard',
                'summary' => '/summary?event={eventId}',
                'teams' => '/teams',
                'team' => '/teams/{teamId}',
                'roster' => '/teams/{teamId}/roster',
                'schedule' => '/teams/{teamId}/schedule',
                'standings' => '/standings',
                'news' => '/news',
                'rankings' => '/rankings',
            ],

            'core' => [
                'seasons' => '/seasons',
                'events' => '/events',
                'odds' => '/events/{eventId}/competitions/{competitionId}/odds',
                'predictor' => '/events/{eventId}/competitions/{competitionId}/predictor',
            ],
        ],

        'nba' => [
            'sport' => 'basketball',
            'league' => 'nba',
            'leagueShort' => 'nba',

            'site' => [
                'scoreboard' => '/scoreboard',
                'summary' => '/summary?event={eventId}',
                'teams' => '/teams',
                'team' => '/teams/{teamId}',
                'roster' => '/teams/{teamId}/roster',
                'schedule' => '/teams/{teamId}/schedule',
                'standings' => '/standings',
                'news' => '/news',
            ],

            'core' => [
                'seasons' => '/seasons',
                'events' => '/events',
                'weekly_events' => '/seasons/{year}/types/{seasonType}/weeks/{week}/events',
                'plays' => '/events/{eventId}/competitions/{competitionId}/plays?limit=300',
                'odds' => '/events/{eventId}/competitions/{competitionId}/odds',
                'predictor' => '/events/{eventId}/competitions/{competitionId}/predictor',
            ],
        ],

        'wnba' => [
            'sport' => 'basketball',
            'league' => 'wnba',
            'leagueShort' => 'wnba',

            'site' => [
                'scoreboard' => '/scoreboard',
                'summary' => '/summary?event={eventId}',
                'teams' => '/teams',
                'team' => '/teams/{teamId}',
                'roster' => '/teams/{teamId}/roster',
                'schedule' => '/teams/{teamId}/schedule',
                'standings' => '/standings',
                'news' => '/news',
            ],

            'core' => [
                'seasons' => '/seasons',
                'events' => '/events',
                'weekly_events' => '/seasons/{year}/types/{seasonType}/weeks/{week}/events',
                'plays' => '/events/{eventId}/competitions/{competitionId}/plays?limit=300',
                'odds' => '/events/{eventId}/competitions/{competitionId}/odds',
                'predictor' => '/events/{eventId}/competitions/{competitionId}/predictor',
            ],
        ],

        'nfl' => [
            'sport' => 'football',
            'league' => 'nfl',
            'leagueShort' => 'nfl',

            'site' => [
                'scoreboard' => '/scoreboard',
                'summary' => '/summary?event={eventId}',
                'teams' => '/teams',
                'team' => '/teams/{teamId}',
                'roster' => '/teams/{teamId}/roster',
                'schedule' => '/teams/{teamId}/schedule',
                'standings' => '/standings',
                'news' => '/news',
            ],

            'core' => [
                'seasons' => '/seasons',
                'events' => '/events',
                'weekly_events' => '/seasons/{year}/types/{seasonType}/weeks/{week}/events',
                'teams_by_season' => '/seasons/{year}/teams',
                'team_by_season' => '/seasons/{year}/teams/{teamId}',
                'team_injuries' => '/teams/{teamId}/injuries?limit=100',
                'team_depthcharts' => '/seasons/{year}/teams/{teamId}/depthcharts',

                'plays' => '/events/{eventId}/competitions/{competitionId}/plays?limit=300',
                'probabilities' => '/events/{eventId}/competitions/{competitionId}/probabilities?limit=200',
                'odds' => '/events/{eventId}/competitions/{competitionId}/odds',
                'predictor' => '/events/{eventId}/competitions/{competitionId}/predictor',

                'futures' => '/seasons/{year}/futures',
                'ats' => '/seasons/{year}/types/2/teams/{teamId}/ats',

                'odds_movement' => '/events/{eventId}/competitions/{competitionId}/odds/{providerId}/history/0/movement?limit=100',
                'head_to_heads' => '/events/{eventId}/competitions/{competitionId}/odds/{providerId}/head-to-heads',
                'past_performances' => '/teams/{teamId}/odds/{providerId}/past-performances?limit=200',

                'qbr_weekly' => '/seasons/{year}/types/2/weeks/{week}/qbr/10000',
            ],

            'cdn' => [
                'scoreboard' => '/scoreboard?xhr=1',
                'schedule' => '/schedule?xhr=1',
                'standings' => '/standings?xhr=1',
                'boxscore' => '/boxscore?xhr=1&gameId={eventId}',
                'playbyplay' => '/playbyplay?xhr=1&gameId={eventId}',
                'recap' => '/recap?xhr=1&gameId={eventId}',
                'matchup' => '/matchup?xhr=1&gameId={eventId}',
                'game' => '/game?xhr=1&gameId={eventId}',
            ],
        ],

        'mlb' => [
            'sport' => 'baseball',
            'league' => 'mlb',
            'leagueShort' => 'mlb',

            'site' => [
                'scoreboard' => '/scoreboard',
                'summary' => '/summary?event={eventId}',
                'teams' => '/teams',
                'team' => '/teams/{teamId}',
                'roster' => '/teams/{teamId}/roster',
                'schedule' => '/teams/{teamId}/schedule',
                'standings' => '/standings',
                'news' => '/news',
            ],

            'core' => [
                'seasons' => '/seasons',
                'events' => '/events',
                'weekly_events' => '/seasons/{year}/types/{seasonType}/weeks/{week}/events',
                'plays' => '/events/{eventId}/competitions/{competitionId}/plays?limit=300',
                'odds' => '/events/{eventId}/competitions/{competitionId}/odds',
                'predictor' => '/events/{eventId}/competitions/{competitionId}/predictor',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Betting Provider IDs (commonly referenced)
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'caesars' => 38,
        'draftkings' => 41,
        'bet365' => 2000,
    ],
];
