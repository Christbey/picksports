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
                'team_injuries' => '/teams/{teamId}/injuries?limit=100',
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
                'team_by_season' => '/seasons/{year}/teams/{teamId}',
                'team_depthcharts' => '/seasons/{year}/teams/{teamId}/depthcharts',
                'team_injuries' => '/teams/{teamId}/injuries?limit=100',
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
                'team_injuries' => '/teams/{teamId}/injuries?limit=100',
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
                'team_by_season' => '/seasons/{year}/teams/{teamId}',
                'team_depthcharts' => '/seasons/{year}/teams/{teamId}/depthcharts',
                'team_injuries' => '/teams/{teamId}/injuries?limit=100',
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
                'team_by_season' => '/seasons/{year}/teams/{teamId}',
                'team_depthcharts' => '/seasons/{year}/teams/{teamId}/depthcharts',
                'team_injuries' => '/teams/{teamId}/injuries?limit=100',
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
                'season_coaches' => '/seasons/{year}/coaches?limit=50&page={page}',
                'season_coach' => '/seasons/{year}/coaches/{coachId}',
                'coach' => '/coaches/{coachId}',
                'team_coaches' => '/seasons/{year}/teams/{teamId}/coaches',
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

            'official_coach_roster_urls' => [
                'ARI' => 'https://www.azcardinals.com/team/coaches-roster/',
                'ATL' => 'https://www.atlantafalcons.com/team/coaches-roster/',
                'BAL' => 'https://www.baltimoreravens.com/team/coaches-roster/',
                'BUF' => 'https://www.buffalobills.com/team/coaches-roster/',
                'CAR' => 'https://www.panthers.com/team/coaches-roster/',
                'CHI' => 'https://www.chicagobears.com/team/coaches/',
                'CIN' => 'https://www.bengals.com/team/coaches-roster/',
                'CLE' => 'https://www.clevelandbrowns.com/team/coaches-roster/',
                'DAL' => 'https://www.dallascowboys.com/team/coaches-roster/',
                'DEN' => 'https://www.denverbroncos.com/team/coaches-roster/',
                'DET' => 'https://www.detroitlions.com/team/coaches-roster/',
                'GB' => 'https://www.packers.com/team/coaches-roster/',
                'HOU' => 'https://www.houstontexans.com/team/coaches-roster/',
                'IND' => 'https://www.colts.com/team/coaches-roster/',
                'JAX' => 'https://www.jaguars.com/team/coaches-roster/',
                'KC' => 'https://www.chiefs.com/team/coaches-roster/',
                'LAC' => 'https://www.chargers.com/team/coaches-roster/',
                'LAR' => 'https://www.therams.com/team/coaches-roster/',
                'LV' => 'https://www.raiders.com/team/coaches-roster/',
                'MIA' => 'https://www.miamidolphins.com/team/coaches-roster/',
                'MIN' => 'https://www.vikings.com/team/coaches-roster/',
                'NE' => 'https://www.patriots.com/team/coaches-roster/',
                'NO' => 'https://www.neworleanssaints.com/team/coaches-roster/',
                'NYG' => 'https://www.giants.com/team/coaches-roster/',
                'NYJ' => 'https://www.newyorkjets.com/team/coaches-roster/',
                'PHI' => 'https://www.philadelphiaeagles.com/team/coaches-roster/',
                'PIT' => 'https://www.steelers.com/team/coaches-roster/',
                'SEA' => 'https://www.seahawks.com/team/coaches-roster/',
                'SF' => 'https://www.49ers.com/team/coaches-roster/',
                'TB' => 'https://www.buccaneers.com/team/coaches-roster/',
                'TEN' => 'https://www.tennesseetitans.com/team/coaches-roster/',
                'WSH' => 'https://www.commanders.com/team/coaches-roster/',
            ],

            'historical_head_coaches' => [
                2025 => [
                    'ARI' => 'Jonathan Gannon',
                    'ATL' => 'Raheem Morris',
                    'BAL' => 'John Harbaugh',
                    'BUF' => 'Sean McDermott',
                    'CAR' => 'Dave Canales',
                    'CHI' => 'Ben Johnson',
                    'CIN' => 'Zac Taylor',
                    'CLE' => 'Kevin Stefanski',
                    'DAL' => 'Brian Schottenheimer',
                    'DEN' => 'Sean Payton',
                    'DET' => 'Dan Campbell',
                    'GB' => 'Matt LaFleur',
                    'HOU' => 'DeMeco Ryans',
                    'IND' => 'Shane Steichen',
                    'JAX' => 'Liam Coen',
                    'KC' => 'Andy Reid',
                    'LAC' => 'Jim Harbaugh',
                    'LAR' => 'Sean McVay',
                    'LV' => 'Pete Carroll',
                    'MIA' => 'Mike McDaniel',
                    'MIN' => "Kevin O'Connell",
                    'NE' => 'Mike Vrabel',
                    'NO' => 'Kellen Moore',
                    'NYG' => 'Brian Daboll',
                    'NYJ' => 'Aaron Glenn',
                    'PHI' => 'Nick Sirianni',
                    'PIT' => 'Mike Tomlin',
                    'SEA' => 'Mike Macdonald',
                    'SF' => 'Kyle Shanahan',
                    'TB' => 'Todd Bowles',
                    'TEN' => 'Brian Callahan',
                    'WSH' => 'Dan Quinn',
                ],
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
                'team_injuries' => '/teams/{teamId}/injuries?limit=100',
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
