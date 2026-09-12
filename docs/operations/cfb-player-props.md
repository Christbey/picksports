# College football and CFP player props

College football uses `americanfootball_ncaaf` and `cfb_player_props`, including January postseason games (season type 3). Initial supported markets are `player_pass_yds`, `player_rush_yds`, and `player_reception_yds`. FanDuel and DraftKings are requested through The Odds API's event odds endpoint; availability varies by game and book.

## Operation

Run `php artisan cfb:sync-odds` first if event mappings are missing. Then run:

```sh
php artisan cfb:sync-player-props --date=2026-09-12 --prepare
```

Dates are America/Chicago game dates, including the following UTC morning. `--game=111` restricts to one internal game ID. `--no-analyze` fetches only. No quotes are requested for games already started. The hourly schedule runs at :15, 07:00–23:00 Central during August–January, with overlap and single-server guards.

`--prepare` runs `cfb:prepare-player-props` after import. Preparation refreshes participating prop-team rosters (once daily), injury reports, and up to six recent final games per team from the current or previous season. Previously populated team box scores are skipped. Use `--games-per-team=16 --refresh` on the preparation command to expand or repair history. Preparation then resolves player IDs and computes recommendations. It does not backfill every historical season.

`php artisan cfb:grade-player-props --season=2026` grades final games with scores and available player stats. Game-detail ingestion also grades immediately after player stats are imported; a daily 08:40 Central grading sweep provides a fallback.

## Data and model behavior

- Player matching requires exactly one normalized full name across the game's two teams. Ambiguous names remain unlinked.
- Quote identity includes game, book, player, market, and line. Refreshes update the same quote; line changes preserve the previous row and grading history. Successful empty responses retire prior quotes. Failed responses preserve data, while freshness rules still expire recommendations.
- Recommendations require current two-sided quotes fetched within 90 minutes, a scheduled future kickoff, a linked player on a participating team, and at least three reported historical stat values. Provider timestamps older than 90 minutes also disqualify a quote.
- Historical samples exclude the target/future dates, other-team stats, and seasons older than the preceding season. Missing stat categories remain missing. Transfers and first-year players may have insufficient evidence for a recommendation.
- Active injury statuses other than Active, Available, or Probable block that player's recommendations. **No injury report does not establish health. Teammate injuries and depth-chart workload redistribution are not modeled.** This limitation is recorded in prediction decomposition and reasoning. CFB probabilities have not been separately calibrated or validated for betting profitability.
- Grading maps the three yardage markets to their corresponding football fields. Missing categories and unsupported markets remain pending. Integer-line pushes have `hit_over = null` and are excluded from Brier calibration.

The board is `/cfb/player-props`; authenticated API endpoints include `/api/v2/sports/cfb/player-props/board` and `/api/v2/sports/cfb/markets/player-props`. Raw API history includes `is_current`; archived quotes are retained. An empty recommendation board can mean insufficient history rather than no API markets.

## Readiness checks

The initial production audit on September 12, 2026 found 71 mapped games, zero CFB players, and zero CFB player-stat rows. Fetching alone was therefore insufficient. After deployment, verify imported quote counts, linked players, available stat samples, and actual board recommendations separately. Monitor the command's `failed`, `empty`, `linked`, `stat_rows`, and `recommendations` counts; do not equate stored quotes with scored recommendations.

Coverage tests: `tests/Feature/CFB/PlayerPropsPipelineTest.php` exercises fetching, alternate lines, idempotency, preserved grades, API failures, empty responses, playoff dates, grading, eligibility, historical leakage prevention, authenticated endpoints, and roster-before-stats preparation.
