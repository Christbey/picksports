export const mappingsProps = (player = false, empty = false) => ({
    mappings: {
        data: empty
            ? []
            : [
                  {
                      id: 1,
                      sport: 'basketball_nba',
                      odds_api_team_name: 'External Home',
                      espn_team_name: 'Home Team',
                      odds_api_player_name: 'External Player',
                      espn_player_name: 'Home Player',
                  },
                  {
                      id: 2,
                      sport: 'basketball_nba',
                      odds_api_team_name: 'Suggested Away',
                      espn_team_name: null,
                      suggested_espn_team_name: 'Away Team',
                      odds_api_player_name: 'Suggested Player',
                      suggested_espn_player_name: 'Away Player',
                      suggested_player_id: 0,
                      suggested_match_quality_score: 0,
                  },
              ],
        current_page: 1,
        last_page: 3,
        per_page: 20,
        total: 40,
    },
    espnTeams: [
        { id: 1, name: 'Home Team', abbreviation: 'HOM' },
        { id: 2, name: 'Away Team', abbreviation: 'AWY' },
    ],
    currentSport: 'basketball_nba',
    currentFilter: 'all',
    currentProvider: 'odds',
    entityType: player ? 'player' : 'team',
    indexBase: player ? '/settings/player-mappings' : '/settings/team-mappings',
    mutationBase: player
        ? '/settings/player-mappings'
        : '/settings/team-mappings',
    queryParams: { provider: 'odds', custom: 'a & b' },
    pageTitle: '',
    pageDescription: '',
    externalSourceLabel: 'Odds API',
    emptyStateCommand: 'php artisan example:sync',
    stats: { total: 40, mapped: 20, unmapped: 20 },
    sports: [
        { key: 'basketball_nba', label: 'NBA' },
        { key: 'americanfootball_nfl', label: 'NFL' },
    ],
    providers: [
        { key: 'odds', label: 'Odds API' },
        { key: 'cfbd', label: 'CFBD' },
    ],
});
export const alertProps = (variant = 'normal') => ({
    preference:
        variant === 'defaults'
            ? null
            : {
                  enabled: true,
                  sports: ['nfl', 'nba'],
                  notification_types:
                      variant === 'phone' ? ['whatsapp'] : ['email', 'push'],
                  enabled_template_ids: [7],
                  minimum_edge: 0,
                  time_window_start: '09:00',
                  time_window_end: '23:00',
                  digest_mode: 'daily_summary',
                  digest_time: null,
                  daily_digest_subscribed: true,
                  phone_number: '+15551234567',
              },
    webPush: {
        configured: variant !== 'unconfigured',
        publicKey: 'AQID',
        hasSubscription: variant === 'subscribed',
    },
});
