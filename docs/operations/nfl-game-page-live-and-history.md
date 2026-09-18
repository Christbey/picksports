# NFL game page: live estimates and historical context

## Data contracts

- `/api/v2/sports/nfl/games/{game}/live-snapshot` is authenticated, read-only, and `no-store`. It reads a game and prediction in one database transaction, then computes a projection from that same score/clock snapshot. It does not persist predictions, run research, load matchup history, or issue bets.
- The page requests live snapshots every 15 seconds while live and visible, every 60 seconds before kickoff, resumes after becoming visible, and stops after final status or unmount. Team stats are requested at most once a minute while live. Heavy matchup/trend requests are not part of the poll loop.
- Source time is the game row's update time, not the time the browser requested a new projection. Missing/invalid timestamps, refresh failures, or age over three minutes produce visible warnings. This timestamp is not an independent upstream heartbeat.
- Live values are experimental score/clock estimates, **not calibrated betting probabilities or a comparison with live sportsbook prices**. They exclude possession, field position, down/distance, timeouts and overtime possession state. The revised heuristic fixes time-boundary behavior, but must be backtested before any trading use. Invalid game state suppresses projection; `0:00` while still live is not settlement. Overtime remaining time refers to the current period.

## History scopes

- NFL head-to-head uses available completed prior meetings of the applicable season type across seasons, strictly before kickoff. The most recent meeting includes date, season, team labels and score. DET/BUF includes the December 15, 2024 meeting: BUF 48, DET 42.
- Season record and role splits remain current-season context. QB matchup history has a separately labeled historical scope. Likely QB selection excludes known unavailable players using evidence available by the earlier of now and kickoff.
- Day/night uses localized kickoff time. Trend requests pass the explicit season type and UTC kickoff cutoff; preseason is not silently mixed into regular-season trends.
- Sample counts are actual per-team counts, including zero. Pattern ranks summarize contextual, unvalidated evidence; they are not probabilities or betting edges.

## Verification

Run the NFL action/feature suites, V2 endpoint contracts, cross-sport matchup tests, and `node --experimental-strip-types --test tests/frontend/*.test.mjs`. Follow with frontend type checking, lint and build. Check game 1722 on production after deployment for its 2024 meeting, separate regular-season sample counts, Night record, and live snapshot refresh/warnings. Do not rewrite historical forecasts or grades to verify presentation changes.
