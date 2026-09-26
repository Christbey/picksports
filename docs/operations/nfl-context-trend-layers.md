# NFL context trend layers — descriptive audit

These layers accompany predictions; they do not change a winner, spread, probability,
confidence score, release or wager approval. The current implementation is an offline
audit, not a deployed API, scheduled job or Vue feature.

## Definitions

| Layer | Historical comparison |
| --- | --- |
| Noon | Kickoff 12:00–12:59 America/Chicago, on any day |
| 3 p.m. | Kickoff 15:00–15:59 America/Chicago, including 3:05 and 3:25 |
| Night / primetime time window | Kickoff 18:00–22:59 America/Chicago, on any day; a time bucket, not proof of national broadcast |
| Coach vs rookie starting QB | Actual recorded opposing starter's verified career rookie season equals game season; not first start or first year as full-time starter |
| Coach vs rookie head coach | Opposing coach's first NFL head-coaching season, not first season with a new team; interim debut policy requires explicit source review |
| Top/bottom 5/10 scoring defense | Opponent's points allowed per regular-season game before the game date; lower is better; all 32 teams require at least 3 games |
| Previous-season playoff opponent | Opponent appeared in the preceding season's complete postseason field; non-playoff is a separate cohort |

Other kickoff times (international morning, unusual doubleheaders, holiday early
afternoon) remain `other`, not forced into the requested buckets. Day of week is
not additionally filtered. The historical week-number restriction is removed.

“Defense” in this audit means scoring defense, including scores allowed by special
teams/offensive turnovers in final scores. It is not EPA, yards allowed, or an
isolated defensive-unit rating. Do not relabel it as such. The existing matchup
signal service's EPA rankings remain separate and need complete play coverage.

## Calculation and display contract

- Emit both team perspectives. No home-team preference or automatic recommendation.
- ATS cover margin = team score − opponent score + signed historical closing spread.
- ATS W/L/P and outright W/L/T are separate. Win percentage excludes pushes.
- Moneyline outcome is an outright result, not moneyline profit or a price-based edge.
- Each layer exposes team history, team at the presented exact line/venue, league
  at that exact line/venue, and current coach's cross-team history when identified.
- The identity comparison additionally separates team, coach across teams, starting
  QB across teams, coach/QB intersection, and each current-team intersection. Compare
  overall, same kickoff window, home underdog, exact line/venue, and line/venue/window.
  Paired records are intersections, never sums or independent votes. Empty known
  identities are `no_sample`; unresolved identities are `unavailable`.
- Current QBs are explicitly projected when sourced from saved model metadata. Local
  player IDs are not GSIS IDs: use an unambiguous exact-name-to-historical-GSIS match
  or leave the QB unresolved. Historical QB records mean games listed as starter,
  including early exits, not complete games played. Archive records beginning in
  2009 must not be called full career records for earlier debuts.
- Historical records use their original lines. A replay against today's line is
  a different calculation and is not substituted here.
- Show sample size and missing coverage. Small/empty samples are not confidence.
- Default layers are separate. Do not silently intersect every filter or add
  correlated trends together as independent votes. Top 5 overlaps top 10.
- Rankings use only completed regular-season games on earlier dates. Same-day
  results and final-season ranks cannot leak into earlier matchups. Ties spanning
  a ranking boundary do not qualify for that band.
- Previous-season playoff classification requires 11 games/12 participants through
  2019 or 13 games/14 participants thereafter. Missing field means unknown.
- Historical coach/QB rookie-year maps must come from verified career records.
  An archive's first appearance is not a debut. Missing identity is null, not false.

## Production-backed audit, September 21, 2026

Read-only coverage checks found 4,431 completed regular-season games from 2009–2025
and 4,431 trustworthy original-source closing lines. All kickoff times were matched
back to nflverse schedule IDs, teams, seasons and scores. Original schedule times
are Eastern; the audit converts them to Central with daylight saving. It does not
trust or overwrite the old importer’s incorrectly localized kickoff timestamps.

Source: [nflverse schedules](https://raw.githubusercontent.com/nflverse/nfldata/master/data/games.csv)
and [schedule dictionary](https://raw.githubusercontent.com/nflverse/nflreadr/main/data-raw/dictionary_schedules.csv).
The report preserves the fetched source SHA-256 and each supporting production game ID.

Coverage, expressed as team appearances (two per game):

- Kickoff window: 8,862 / 8,862.
- Prior playoff status: 8,350 / 8,862; 2009 lacks a production 2008 postseason field.
- Qualified pregame scoring-defense rank: 7,202 / 8,862; early-season appearances are excluded.
- Verified career rookie classifications: unavailable; no mappings supplied. Production
  coach-season profiles only cover 2025–2026, and players lack a historical rookie-year field.
- Current Week 3 defensive rankings: unqualified under the three-game minimum. The
  historical band tables are exploratory history, **not a current matchup signal**.

Completed 2026 games are not pooled into this closing-line archive audit. Current
quotes are saved observed pregame quotes with explicit capture timestamps, not
promised live prices. Neutral-site games remain neutral, including BAL–DAL.

## Reproduction

Load `NflHistoricalMarketEvidence`, `scripts/audits/NflContextLayerAudit.php`, and
`scripts/audits/NflContextLayerProductionReport.php` in a read-only production
Tinker session; call `Audit\NflContextLayerProductionReport::run(2026, 3)`.
The runner reads production rows and the public source CSV only. It performs no
database writes, research-provider requests, model regeneration or deployment.

Tests: `php artisan test --compact tests/Feature/NFL/NflContextLayerAuditTest.php`.
The audit is reconstructed from today's historical database, not a point-in-time
backtest of information the system actually possessed before each past game.
