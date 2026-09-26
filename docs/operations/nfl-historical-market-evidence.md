# NFL historical market evidence

Local implementation, September 21, 2026. Not deployed by this task.

## Purpose and UI

Show historical support **and counterevidence** beside a prediction without
changing the forecast, spread, confidence, research approval or wager decision.
The game Trends section and prediction-details drawer expose one lazy-loaded
“Historical spread evidence” panel. No OpenAI calls or paid provider refreshes.

Choose the starting season (default 2009) and either use the saved pregame quote
or type an explicitly hypothetical home handicap. Negative means home favorite;
positive means home underdog. Each team gets the opposite signed perspective.
The displayed source/book/capture time can differ from a prediction's older
frozen market. It is never presented as that prediction's execution price.

Rows are predefined, not selected because they happen to have a high win rate:

- All teams at the exact signed closing spread, all venues.
- All teams at the same spread and home/away/neutral venue classification.
- This team at that spread, all venues.
- This team at that spread and venue.
- This team + game-recorded coach, at that spread and venue.
- This team + game-recorded starting QB, at that spread and venue.
- This team + coach + starting QB, at that spread and venue.

The first four rows are visible; identity splits and additional statistics are
collapsed. Missing identities produce “unavailable,” not an invented coach/QB
from the current roster. Stable provider QB IDs take priority; an exact normalized
name fallback is labeled. Coach matching is whitespace/case normalization only.
These are **team-and-coach/QB** splits, not career coaching/QB records across teams.

## Formula contract

For each prior game and selected team:

```
margin = team final score - opponent final score
handicap = historical sportsbook spread from that team's perspective
cover_margin = margin + handicap
ATS win: cover_margin > 0; loss: < 0; push: = 0
Outright win: margin > 0; loss: < 0; tie: = 0
ATS win percentage = wins / (wins + losses) * 100
average_margin = sum(margin) / team appearances
average_cover_margin = sum(cover_margin) / team appearances
```

Only historical games actually priced at the requested handicap qualify. This
does **not** apply today's spread to every historical scoring margin. No nearby
line grouping: -3, -3.5 and +3 are different cohorts.

At zero, both sides qualify for the all-venue league cohort. Responses expose
both team-appearance and unique-game counts; those observations are not
independent. Rows overlap and must not be combined as independent signals.

Samples under 20 appearances are visibly flagged, not discarded. Twenty is a UI
warning boundary, not a statistically validated confidence threshold. Returns,
ROI and price-conditioned moneyline probabilities are not calculated: outright
W–L–T alone does not measure value at a particular moneyline price.

## Source, cutoff and integrity

Historical cohorts use `game_odds_snapshots` with sport `nfl`, table `nfl_games`,
source `nflverse`, book `nflverse_closing`, context source `nflverse_schedules`
and `line_type=closing`. The original `market_context.spread_line` uses positive
home-favored margin; sportsbook home handicap is its **negative**. This reads
the original source convention rather than trusting the reversed legacy payload.
It does not rewrite stored snapshots, grades or forecasts.

One line per game. Identical copies deduplicate to the earliest snapshot ID;
invalid/conflicting source lines exclude the game. Only finite half-point-step
lines in [-60, 60] qualify. Other books, live markets, mutable game `odds_data`,
and observed current-season quotes are not mixed into the closing archive.
Coverage reports every otherwise eligible game excluded for absent/conflicting
lines. This is not an assertion that the archive covers every NFL game ever.

Eligible results are final, scored regular-season games since the requested
season, before both the target calendar date and today's date. The target,
same-date results, preseason and playoffs are excluded. This is a reconstruction
from stored history, including subsequent corrections; synthetic archive capture
times are never evidence of contemporaneous availability.

Default target line: newest immutable `odds_api` snapshot before kickoff and now,
with matching scheduled kickoff and provider event ID when present. Validate
payload teams, selected bookmaker and equal/opposite spread outcomes. Canonical
event kickoff takes priority over the legacy UTC date/time. UTC kickoff is
converted to application timezone for snapshot storage queries. A malformed
latest payload is unavailable, not silently replaced by older/mutable odds.
Completed archive matchups may fall back to explicitly labeled retrospective
closing lines. Quotes show capture time; this panel does not certify freshness
or execution availability.

## Endpoint and performance

`GET /api/v2/sports/nfl/games/{game}/market-history?since=2009&home_line=-5.5`

Existing authentication, sport access and game lookup precede cache access.
Cache TTL 120 seconds, keyed by contract version, game/update timestamp,
starting season and optional handicap. Cache expiry incorporates quote/history
changes even when the game timestamp is unchanged. No model database writes.

Use a narrow Eloquent game query plus one bulk archive snapshot query (only IDs
and market context), then in-memory reductions. No per-game database queries,
prediction hydration, team metrics or play-by-play scans. The UI fetches only
on opening/applying filters, aborts superseded requests and never polls.

## Read-only production check

The new service was evaluated transiently against production game 1722, without
deployment or persistence. Its saved DraftKings pregame home spread was BUF -5.5
(snapshot 19655, September 17 at 18:20:08 CDT). The query completed in 704 ms.
With the September 18 cutoff and since-season 2009:

| Cohort | ATS W–L–P | Outright W–L–T | Appearances |
| --- | --- | --- | --- |
| All teams favored by 5.5 | 81–99–0 | 119–60–1 | 180 |
| Home teams favored by 5.5 | 46–63–0 | 68–40–1 | 109 |
| Buffalo favored by 5.5, any venue | 3–4–0 | 5–2–0 | 7 |
| Buffalo at home, favored by 5.5 | 1–1–0 | 2–0–0 | 2 |

4,431 of 4,447 eligible prior regular-season games had usable archive lines;
16 were excluded. Latest covered date was January 4, 2026 (2025 regular season).
Game 1722 has no game-recorded coach/QB identities, so those splits correctly
remain unavailable. Historical identity support is implemented, but populating
verified target identities and closing-line coverage for new games remains a
data-ingestion requirement. No guesses or production data mutations were made.

## Verification

`php artisan test --compact tests/Feature/NFL/NflHistoricalMarketEvidenceTest.php`

Covers signs, exact-line matching, pushes/ties, duplicates/conflicts, past/future
cutoffs, season restrictions, neutral venues, coach/QB changes, missing identities,
pick'em appearances, observed-quote identity/timing, storage timezones, bounded
queries, authentication, validation, cache isolation, and read-only/no-provider
behavior. Also run the existing matchup endpoint/situational suites, frontend
typecheck, ESLint and production build after integration.

Completed verification: 570 NFL feature/endpoint tests passed (3,610 assertions),
including 12 new market-evidence tests. TypeScript, targeted ESLint, Pint,
`git diff --check`, and the production asset build passed. A synthetic 390px-wide
browser fixture rendered the expanded panel, records and collapsed details;
this was a UI smoke check, not a production deployment or visual screenshot audit.
