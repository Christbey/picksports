# NFL player-prop season statistics

The recommendation board exposes current regular-season evidence separately from historical model inputs.

- `stats.season_avg`, `stats.season_summary`, and `stats.cover_record.season` use the matchup's `season`, not the current calendar year.
- Only finalized regular-season games strictly before the matchup kickoff are included. The matchup itself, preseason, postseason, and later games are excluded.
- Missing reported statistics are excluded and counted in `missing_stat_games`. A real zero is included. No current-season sample returns a null average and record, never a historical fallback.
- `stats.historical_avg` retains the stored model baseline (up to 82 prior games). `stats.cover_record.historical_last_17` retains the stored rolling NFL record.
- Cover records compare historical performances with the displayed line; they are not settled wager performance. Presented NFL win rates exclude pushes.
- The board batches historical statistics into one query for its rendered recommendations plus one small team lookup. It does not rerun forecasts, fetch provider data, generate AI research, or rewrite model snapshots.

`stats.cover_record` also includes `last_season`, `all_time`, `vs_opponent`, and `vs_conference`. All use available regular-season records before the matchup and the presented line/side. Opponent and conference splits use the player's stat-row team at each historical game, including before trades. Conference membership uses current stored team metadata, not historical realignment data. Missing conference metadata or no eligible sample returns null and displays N/A. All-time means all available stored history, not guaranteed career completeness.

The Vue page displays the season, reported sample size, historical baseline, weighted recent averages, sportsbook, original quote timestamp, and freshness status. The NFL quote window is 24 hours. Displaying age is not a replacement for backend recommendation eligibility checks.

Regression coverage: `NflPropSeasonSummaryTest`, `NflPlayerPropBoardTimeTest`, and `playerPropPresentation.test.mjs`.

The once-daily `nfl:sync-player-props` command chains `sports:analyze-player-props --sport=nfl --only-missing` after a successful fetch. Quote replacement clears snapshots, so analysis must follow the fetch. This step uses no AI narratives and does not fetch prices again. An analysis failure propagates as a nonzero sync-command exit code.
