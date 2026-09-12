# College football live betting projections

## Preservation

`cfb_live_prediction_snapshots` stores timestamped, append-only game-state snapshots. The first capture freezes the existing `cfb_predictions` pregame spread, total and win probability as its baseline; subsequent snapshots reuse that baseline. This is a capture of the existing pregame forecast, not a reconstruction of a missing pregame forecast. The live updater changes only the legacy `live_*` fields. Pregame forecasts, `cfb_player_props` quotes and their prediction fields are never overwritten by live captures.

Scoreboard changes and complete live-feed captures are both recorded. Identical captures in the same minute are deduplicated. Final game results are appended and live display columns are cleared; previous snapshots remain available. Eloquent rejects snapshot updates/deletes. Snapshot rows retain model inputs and outputs, market lines and player estimates for audit.

## Operation

```sh
php artisan cfb:sync-live-betting --game=151
php artisan cfb:sync-live-betting --limit=40
```

The scheduled command runs every two minutes during August–January in the background with overlap and single-server guards. It selects in-progress games and final games whose live-feed finalization remains pending. The existing five-minute CFB scoreboard window now starts at 10:00 Central, ahead of the early slate.

Each game uses an uncached ESPN summary and an uncached Odds API event request for FanDuel/DraftKings game lines and the three supported yardage prop markets. The API event ID must match. ESPN supplies the score, period, clock and reported player stats. Odds requests and live snapshots do not modify `games.odds_data` or the pregame prop table.

The initial provider probe returned live spreads, totals and moneylines, but no player-prop markets for that particular game. Player-prop availability is game/book dependent. A missing live line is displayed as unavailable; pregame lines are never substituted as live lines.

## Models and safeguards

Game estimates blend the pregame forecast with current score and regulation time remaining. Spread values use home-minus-away margin, so sportsbook home handicaps require the opposite sign when comparing. Moneyline differences are against two-sided no-vig implied probabilities. These differences are experimental model comparisons, not calibrated profitable betting edges.

Player estimates use a current-team historical mean from at least three reported prior-game stats, then blend that prior with observed production per elapsed regulation time. They add projected remaining production to the current recorded value. The first live-feed baseline is reused where available. Missing stats are not zero; missing samples, unresolved player injury status, the first five minutes, and missing game state withhold estimates. Depth-chart changes, teammate injury redistribution and possession-level opportunity are not modeled. College overtime is possession-based, so overtime projections are withheld instead of applying NFL clock assumptions.

Market timestamps must be after kickoff, no older than three minutes, and not materially in the future. Snapshot/quote freshness is checked again when serving the API. Failed requests do not reuse older lines as current. Pregame results remain separate from live estimates.

## Display and history

The CFB game page includes a separate pregame/live panel, sportsbook comparisons, player estimates and recent snapshot history. It polls every 30 seconds while visible and stops on unmount. The main scoreboard also refreshes. Game projection fields honor existing prediction-field permissions; authentication and sport API access apply to the endpoint.

`GET /api/v2/sports/cfb/games/{game}/live-betting` returns the most recent live-feed snapshot (or scoreboard snapshot if none exists), the latest 25 history rows, total snapshot count, freshness and model limitations. Snapshot data remains in the database beyond this presentation limit. Historical comparisons retain `lean_at_capture`/`difference_at_capture` even after current comparisons expire. Final game lines are evaluated against final scores; player quote results require recorded final player stats. Missing final box scores use `final_pending_stats` and are retried on later runs. Wins, losses and pushes are returned separately from the original immutable forecasts.

## Validation

`tests/Feature/CFB/LiveBettingTest.php` covers pregame preservation, immutable history, baseline freezing, finalization, missing clocks/scores, college overtime, quote freshness, spread signs, player projection arithmetic, API failures, read-time expiry, final pending stats, authentication and field permissions. Existing NFL updater and CFB pregame prop tests provide regression coverage.
