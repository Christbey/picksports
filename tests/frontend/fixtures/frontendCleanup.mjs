const date = '2026-10-01T12:00:00Z';
export const futuresRows = () =>
    [1, 2, 3].map((id) => ({
        id,
        team_id: id,
        season: 2026,
        team: {
            id,
            abbreviation: `TM${id}`,
            location: 'City',
            name: `Team ${id}`,
            conference: id === 3 ? 'West' : 'East',
            division: 'Central',
            league: id === 3 ? 'NL' : 'AL',
        },
        conference: id === 3 ? 'West' : 'East',
        league: id === 3 ? 'NL' : 'AL',
        conference_rank: id,
        league_rank: id,
        projected_seed: id,
        playoff_make_probability: [0, 0.9, 0.5, 0.2][id],
        direct_playoff_probability: 0.6,
        play_in_tournament_probability: 0.5,
        division_win_probability: 0.3,
        conference_finals_probability: 0.3,
        nba_finals_probability: 0.2,
        champion_probability: 0.1,
        division_series_probability: 0.4,
        league_championship_series_probability: 0.3,
        league_championship_probability: 0.2,
        pennant_probability: 0.2,
        world_series_probability: 0.1,
        selection_score: 0.6,
        market_odds: {
            bookmaker: 'Fixture',
            market_key: 'outrights',
            price: id === 1 ? 150 : -110,
            implied_probability: 0.4,
            fetched_at: date,
            odds_api_sport_key: 'baseball_mlb',
        },
        market_edge: {
            model_probability: 0.5,
            market_probability: 0.4,
            edge_probability: id === 1 ? 0.1 : -0.1,
            edge_percent_points: id === 1 ? 10 : -10,
            fair_price: 100,
            market_price: 150,
            has_edge: id === 1,
        },
    }));
export function healthProps(view = 'heartbeat', empty = false) {
    const ai = {
        headline: 'Pipeline review',
        intro: 'Stored fixture review.',
        highlights: ['Coverage complete'],
        recommended_actions: ['Review failures'],
        latest_data_fresh_at: date,
        data_schedule_today: ['Nightly refresh'],
        tweak_recommendations: ['Keep current thresholds'],
        operational_status: 'review',
        trust_score: 80,
        blocked_outputs: ['Stale quote'],
        safe_adjustments: ['Review freshness'],
        data_quality_notes: ['Small sample'],
        generated_by: 'fixture',
    };
    const run = {
        id: 1,
        scope: 'all',
        status: 'completed',
        summary: { passing: 2, warning: 1, failing: 1 },
        ai_summary: ai,
        ai_generated_at: date,
        completed_at: date,
    };
    return {
        checks_by_sport: empty
            ? {}
            : {
                  nfl: [
                      {
                          id: 1,
                          sport: 'nfl',
                          check_type: 'heartbeat_sync',
                          status: 'warning',
                          message: 'Stored heartbeat\nprivate debug',
                          checked_at: date,
                          metadata: {
                              total_teams: 32,
                              teams_with_games: 30,
                              average_games: 5.2,
                              std_dev: 1.2,
                              in_season: true,
                              is_during_game_hours: false,
                              game_hours: { start: 9, end: 22 },
                              last_success_at: date,
                              age_minutes: 20,
                              command_patterns: ['nfl:sync%'],
                              outliers: [
                                  {
                                      type: 'no_games',
                                      espn_id: 1,
                                      team: 'Missing',
                                      games: 0,
                                  },
                                  {
                                      type: 'high',
                                      espn_id: 2,
                                      team: 'Outlier',
                                      games: 9,
                                      deviation_from_avg: 3,
                                  },
                              ],
                          },
                      },
                  ],
              },
        status_counts: { passing: 2, warning: 1, failing: 1 },
        sports: ['nfl', 'mlb'],
        latest_validation_run: empty ? null : run,
        recent_validation_runs: empty ? [] : [run],
        selected_validation_run: empty
            ? null
            : {
                  ...run,
                  findings: [
                      {
                          id: 1,
                          sport: 'nfl',
                          check_type: 'validation_game_coverage',
                          status: 'failing',
                          severity: 'high',
                          message: 'Fixture finding',
                          facts: { missing: 2 },
                          recommended_action: 'Review games',
                          detected_at: date,
                      },
                  ],
              },
        validation_trend: empty
            ? null
            : {
                  current: { failing: 1, warning: 1, passing: 2 },
                  previous: {
                      id: 0,
                      failing: 2,
                      warning: 1,
                      passing: 1,
                      completed_at: date,
                  },
                  delta: { failing: -1, warning: 0, passing: 1 },
                  direction: 'improving',
                  points: [
                      {
                          id: 1,
                          failing: 1,
                          warning: 1,
                          passing: 2,
                          completed_at: date,
                      },
                  ],
              },
        ai_publishing: {
            total: 3,
            decisions: { publish: 2, block: 1 },
            classifications: { approved: 2, blocked: 1 },
            enforcement: { enabled: true, mode: 'enforce' },
            needs_attention: empty
                ? []
                : [
                      {
                          sport: 'nfl',
                          matchup: 'Away at Home',
                          decision: 'block',
                          publishable_classification: 'blocked',
                          freshness_status: 'stale',
                          market_status: 'missing',
                          model_status: 'ready',
                          summary: 'Review stored quote',
                          required_actions: ['Review quote'],
                      },
                  ],
        },
        ai_publishing_trend: {
            days: 7,
            total: 3,
            changed_count: 1,
            changed_rate: 1 / 3,
            decisions: { publish: 2, block: 1 },
            changed_rows: empty
                ? []
                : [
                      {
                          date,
                          sport: 'nfl',
                          matchup: 'Away at Home',
                          decision: 'block',
                          saved_classification: 'approved',
                          guardrail_classification: 'blocked',
                          recommendation: 'Review',
                      },
                  ],
        },
        filters: { sport: null, view, validation_run: null },
    };
}
export function mlbMonitoringProps(empty = false) {
    const artifact = {
        id: 'artifact-1',
        model_version: 'mlb-v1',
        model_type: 'challenger',
        market_type: 'moneyline',
        status: 'active',
        created_at: date,
        promoted_at: date,
        training_run_id: 'train-fixture',
        feature_version: 'features-v1',
        artifact_hash: 'artifact-hash-fixture',
        dataset_hash: 'dataset-hash-fixture',
        config_hash: 'config-hash-fixture',
        code_version: 'abc123',
        sport: 'mlb',
        evaluation_report_hash: 'evaluation-fixture',
        run_type: 'training',
        metrics: { brier: 0.2 },
        evaluation_summary: { games: 20 },
        promotion_checks: { calibration: true },
        promotion_markets: { moneyline: true },
        promoted_markets: ['moneyline'],
        promotion_summary: { status: 'passed' },
        delta_convention: { brier: 'lower is better' },
        public_output_changed: false,
    };
    return {
        artifacts: empty ? [] : [artifact],
        artifact: empty ? null : artifact,
        lineage: { active: empty ? null : artifact, challenger: null },
        data_health: {
            season: 2026,
            pregame_safe_snapshots: 10,
            latest_pregame_snapshot_at: date,
            snapshot_age_hours: 2,
            pregame_market_quotes: 10,
            latest_market_quote_at: date,
            quote_age_hours: 2,
            upcoming_games: 3,
            probable_pitchers_ready: 2,
            probable_pitcher_coverage: 2 / 3,
            games_with_market_quotes: 3,
            market_quote_coverage: 1,
            market_coverage: empty
                ? []
                : [{ market: 'moneyline', quote_count: 10, game_count: 3 }],
        },
        summary: {
            shadow_observations: 10,
            decisions: 10,
            tracking_bets: 4,
            no_bets: 6,
            settled_decisions: 3,
            pending_decisions: 1,
            profit_units: 0.5,
            roi: 0.1,
            average_clv: 0.02,
            calibration_games: 10,
            baseline_brier: 0.2,
            challenger_brier: 0.19,
            brier_delta: -0.01,
        },
        market_performance: empty
            ? []
            : [
                  {
                      market: 'moneyline',
                      decisions: 10,
                      bets: 4,
                      no_bets: 6,
                      settled: 3,
                      pending: 1,
                      calibration_games: 10,
                      baseline_brier: 0.2,
                      challenger_brier: 0.19,
                      brier_delta: -0.01,
                      profit_units: 0.5,
                      roi: 0.1,
                      average_clv: 0.02,
                  },
              ],
        weekly_performance: empty
            ? []
            : [
                  {
                      week_start: '2026-10-01',
                      games: 10,
                      baseline_brier: 0.2,
                      challenger_brier: 0.19,
                      baseline_log_loss: 0.6,
                      challenger_log_loss: 0.59,
                      baseline_accuracy: 0.6,
                      challenger_accuracy: 0.7,
                      baseline_margin_mae: 2,
                      challenger_margin_mae: 1.9,
                      baseline_total_mae: 3,
                      challenger_total_mae: 2.9,
                  },
              ],
        evaluation_windows: empty
            ? []
            : [{ window: 'last_30', games: 10, brier: 0.2 }],
        observations: empty
            ? []
            : [
                  {
                      id: 'obs-1',
                      matchup: 'Away at Home',
                      game_date: date,
                      market_type: 'moneyline',
                      baseline_output: 0.5,
                      challenger_output: 0.6,
                      output_delta: 0.1,
                      status: 'completed',
                      generated_at: date,
                      pregame_safe: true,
                      feature_hash: 'feature-fixture',
                      inference_run_id: 'run-1',
                      decision: {
                          status: 'tracking',
                          side: 'home',
                          price: 100,
                          edge: 0.1,
                          is_bet: true,
                          is_public: false,
                          is_tracking_only: true,
                          reasons: ['tracking_only'],
                      },
                      settlement: {
                          status: 'won',
                          profit_units: 1,
                          clv: 0.01,
                          settled_at: date,
                      },
                  },
              ],
        no_bet_reasons: empty ? [] : [{ reason: 'missing_market', count: 3 }],
        inference_failures: empty
            ? []
            : [
                  {
                      id: 'failure-1',
                      run_type: 'inference',
                      model_version: 'mlb-v1',
                      feature_version: 'features-v1',
                      status: 'failed',
                      started_at: date,
                      completed_at: date,
                      error: 'Fixture failure',
                  },
              ],
    };
}
export function publicSportProps(empty = false) {
    const injury = {
        id: 1,
        player_name: 'Injured Player',
        team_abbreviation: 'HOM',
        status: 'Out',
        detail: 'Fixture injury',
        type: 'leg',
        injury_date: date,
        return_date: null,
        source_updated_at: date,
        updated_at: date,
        impact_score: 3,
        impact_label: 'High',
    };
    return {
        sport: 'nba',
        sportLabel: 'NBA',
        latestSeason: 2026,
        hasPlayerProps: !empty,
        conferencePlayoffTeams: empty
            ? null
            : {
                  east: [
                      {
                          team_id: 1,
                          seed: 1,
                          projected_seed: 1,
                          name: 'East Team',
                          abbreviation: 'EAST',
                          logo: null,
                          conference: 'East',
                          playoff_make_probability: 0.8,
                          direct_playoff_probability: 0.7,
                      },
                  ],
                  west: [
                      {
                          team_id: 2,
                          seed: 1,
                          projected_seed: 1,
                          name: 'West Team',
                          abbreviation: 'WEST',
                          logo: null,
                          conference: 'West',
                          playoff_make_probability: 0,
                          direct_playoff_probability: 0,
                      },
                  ],
              },
        injuries: { top: empty ? [] : [injury], recent: empty ? [] : [injury] },
        topTeams: empty
            ? []
            : [
                  {
                      rank: 1,
                      team_id: 1,
                      name: 'Top Team',
                      abbreviation: 'TOP',
                      logo: null,
                      record: '5-1',
                      primary_metric_label: 'Rating',
                      primary_metric_value: 0,
                      net_rating: 0,
                  },
              ],
        topPlayers: empty
            ? []
            : [
                  {
                      player_id: 1,
                      name: 'Top Player',
                      team_abbreviation: 'TOP',
                      position: 'G',
                      headshot: null,
                      games_played: 5,
                      headline_stat_label: 'Points',
                      headline_stat_value: 0,
                  },
              ],
        featuredPredictions: empty
            ? []
            : [
                  {
                      id: 'public-1',
                      game_id: 1,
                      matchup: 'Away at Home',
                      game_date: date,
                      status: 'scheduled',
                      pick: 'Home',
                      home_team_abbreviation: 'HOM',
                      away_team_abbreviation: 'AWY',
                      predicted_spread: 0,
                      predicted_total: 210,
                      confidence_score: 70,
                      home_win_probability: 60,
                  },
              ],
        featuredProps: empty
            ? []
            : [
                  {
                      id: 1,
                      player: {
                          id: 1,
                          name: 'Prop Player',
                          team: 'TOP',
                          position: 'G',
                          headshot: null,
                          url: '/nba/players/1',
                      },
                      market: 'player_points',
                      line: 20.5,
                      recommendation: 'Over',
                      odds: -110,
                      confidence: 70,
                      edge: 5,
                      stats: {
                          times_covered_last5: { hits: 3, games: 5 },
                          times_covered_season: { hits: 8, games: 10 },
                      },
                      game: {
                          id: 1,
                          home_team: 'HOM',
                          away_team: 'AWY',
                          date: '2026-10-01',
                          time: '17:00:00',
                      },
                  },
              ],
        links: {
            predictions: '/nba/predictions',
            injuries: '/nba/injuries',
            teamMetrics: '/nba/team-metrics',
            playerStats: '/nba/player-stats',
            playerProps: '/nba/props',
        },
        summary: {
            topInjuriesCount: empty ? 0 : 1,
            recentInjuriesCount: empty ? 0 : 1,
            topTeamsCount: empty ? 0 : 1,
            topPlayersCount: empty ? 0 : 1,
            predictionsCount: empty ? 0 : 1,
            propsCount: empty ? 0 : 1,
        },
    };
}
export function trendsProps(empty = false) {
    return {
        title: 'Team Trends',
        subtitle: 'Stored context',
        trendsLoading: false,
        topMatchupEdges: ['Home scoring context'],
        allTrendCategories: empty ? [] : ['scoring', 'home_away'],
        formatCategoryName: (s) => s.replaceAll('_', ' '),
        isLockedCategory: (s) => s === 'home_away',
        formatTierName: (s) => s,
        getRequiredTier: () => 'pro',
        awayLabel: 'AWY',
        homeLabel: 'HOM',
        awayTrends: empty
            ? null
            : {
                  trends: {
                      scoring: ['Over in 7 of their last 10 games'],
                      home_away: ['Won 70% at home'],
                  },
                  sample_size: 10,
              },
        homeTrends: empty
            ? null
            : {
                  trends: {
                      scoring: ['Allowed 30 points'],
                      home_away: ['Covered 6 of last 8'],
                  },
              },
        matchupContext: empty
            ? null
            : {
                  rows: [
                      {
                          key: 'recent',
                          label: 'Recent form',
                          description: 'Stored history',
                          away: { wins: 3, losses: 2, ties: 0, games: 5 },
                          home: { wins: 4, losses: 1, ties: 0, games: 5 },
                      },
                  ],
              },
        emptyText: 'No stored trends',
    };
}
export function boxProps(layout = 'grid', zero = false) {
    const stats = {
        field_goals_made: zero ? 0 : 30,
        field_goals_attempted: zero ? 0 : 60,
        three_point_made: zero ? 0 : 10,
        three_point_attempted: zero ? 0 : 30,
        free_throws_made: zero ? 0 : 10,
        free_throws_attempted: zero ? 0 : 15,
        rebounds: zero ? 0 : 40,
        assists: zero ? 0 : 20,
        turnovers: zero ? 0 : 10,
        steals: zero ? 0 : 7,
        blocks: zero ? 0 : 4,
        points_in_paint: zero ? 0 : 20,
        fast_break_points: zero ? null : 10,
    };
    return {
        layout,
        awayLabel: 'AWY',
        homeLabel: 'HOM',
        awayTeamStats: { ...stats },
        homeTeamStats: {
            ...stats,
            rebounds: zero ? 0 : 50,
            turnovers: zero ? 0 : 8,
        },
        getBetterValue: (home, away, lower = false) =>
            home === away
                ? null
                : (lower ? home < away : home > away)
                  ? 'home'
                  : 'away',
    };
}
export function cleanupProps(kind, scenario = 'normal') {
    const empty = scenario === 'empty';
    if (kind === 'health')
        return healthProps(
            scenario === 'validation' ? 'validation' : 'heartbeat',
            empty,
        );
    if (kind === 'monitor') return mlbMonitoringProps(empty);
    if (kind === 'public') return publicSportProps(empty);
    if (kind === 'trends') return trendsProps(empty);
    if (kind === 'box')
        return boxProps(
            scenario.startsWith('table') ? 'table' : 'grid',
            scenario.includes('zero'),
        );
    return {};
}
