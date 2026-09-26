# NFL matchup signals and situational records

## Scope and rollout

This is a descriptive, read-only first release of the supplied 353-entry catalog,
not a new probability model or wager-approval system. It does not launch research,
change numeric forecast formulas, or write observations/settlements. It requires
no new migrations or scheduled jobs. Deploy the normal application build to expose
the new authenticated endpoint and the collapsed section under NFL game Trends
and in the prediction-board matchup detail drawer. This local feature has not
been verified as deployed to production.

- Endpoint: `GET /api/v2/sports/nfl/games/{game}/matchup-signals`.
- Main catalog: `App\Services\NFL\Matchups\NflMatchupSignalCatalog`.
- Computation: `App\Services\NFL\Matchups\NflMatchupSignalService`.
- Situations: `App\Services\NFL\NflSituationalRecordService`.
- UI: `NflMatchupSignals.vue` / `NflMatchupSignalEvidence.vue` / `NflMatchupCatalog.vue`.

All 353 supplied IDs and labels are retained. ID 353 is incomplete in the source
list and remains unavailable. The endpoint reconciles the separate situational
implementation with the catalog; it does not label all travel/schedule rules as
implemented just because a situational section exists.

## Implemented definitions

The 52 matchup rules are IDs 1–14, 19–22, 26–29, 32–33, 38–39, 51–62,
77–80, 101–106, 110, 127–128, and 132.
Each is evaluated once for each team's offense against the opposing defense,
yielding 104 evaluations, not 104 independent confirmations.

- EPA/play: overall, passing (including sacks), and rushing.
- Success rate: fraction of eligible plays with EPA strictly greater than zero.
- Separate passing/rushing success and explosive-play rates. Explosive means
  at least 20 passing yards or 10 rushing yards.
- EPA on early downs (1–2), late downs (3–4), first-down passes/runs,
  third-down passes, and red-zone passes (0–20 yards from opponent end zone).
- Short-yardage conversion: eligible pass/run plays with 1–2 yards to go that
  gain at least the required yards. This is not a blocking or charting grade.
- Where source wording says high/elite/strong or low/weak/poor without a numeric
  band, this catalog explicitly uses top/bottom ten; these are descriptive
  definitions, not empirically validated predictive thresholds.
- Yards/play: play-weighted eligible pass/run yardage.
- Team scoring/game and team points allowed/game. These include defensive and
  special-teams scoring; they are not isolated offensive scoring efficiency.
- Higher offensive values rank better; lower allowed defensive values rank better.
- Top/bottom five and ten use ranks across all 32 qualified teams. A tie spanning
  a band boundary does not qualify. Above/below average uses an unweighted mean of
  qualified team averages; defense is interpreted in the opposite direction.

The 19 situational W–L–T records include catalog IDs 294, 296, 298, 304, 306,
308, 310, 312–315, 321, 323, 333, and 335, plus explicitly defined after-win,
after-loss, short-rest, and extended-rest records. These are historical subsets;
the UI does not claim that every subset applies to the selected upcoming game.
Definitions, game IDs, dates, sample size, wins, losses and ties accompany each row.

## Data and cutoff contract

Matchup metrics use mapped `nflverse_pbp_plays` and final `nfl_games`, not mutable
current-season team metric rows. No nfl_plays/provider EPA fallback is silently
mixed into the same ranking. Games must be in the selected regular season and on
a prior UTC calendar date. The migrated games table lacks a reliable completion
timestamp, so same-day results are conservatively excluded. Unknown target
kickoff times and nonregular target phases cannot produce matchup matches.

Every team's preceding final game must meet metric qualification; a missing game
is not silently dropped while calling the remainder a season average. Require:

- At least three qualifying games per team and 32 qualified teams in both units.
- At least 30 eligible plays/game overall, 15 passing or 8 rushing for splits.
- Context floors: 15 early-down, 5 late-down, 8 first-down passing,
  4 first-down rushing, 3 third-down passing/red-zone passing/short-yardage.
- At least 90% non-null metric coverage among the eligible imported plays.
- Unknown context fields count against coverage; they are not guessed or silently
  excluded from the denominator.
- Pass/run plays only, excluding no-play rows. Sacks belong to passing; runs
  include provider-classified scrambles. No charted scheme split is inferred.

These floors do **not** prove the provider imported every play. Absent metric
values remain null; real zero EPA, scores and rates are retained. This is a
reconstruction from currently stored historical data, including later corrections,
not a point-in-time backtest or proof the inputs were available then.

The optional `window` query parameter accepts `season_to_date` (default) or
`previous_season`. The previous-season selection is a separately labeled historical
sample, never an automatic fallback or blended input. Window and catalog version
are part of the cache key. Situational records retain their separately labeled
current-plus-three-season window regardless of this efficiency selection.

Situations use current and previous three regular seasons, before the same date
cutoff. Sequence rules never cross seasons and require adjacent schedule weeks
with known kickoffs 4–14 Eastern calendar days apart. Gaps are excluded because
missing imports and byes cannot be distinguished here. Missing/nonfinal games
break sequences. Home/road exclude neutral sites. Thursdays/rest use Eastern dates.
Blowouts mean a margin of at least 21; one-score results mean a margin of 1–8.
Exactly two-game streaks remain separate from three-or-more streaks. Ties are not
losses. Under-three-game samples remain visibly insufficient.

ATS and totals records are unavailable in this new section until immutable
historical market snapshots are supplied. Existing application grading and profit
tracking are not replaced. Current mutable odds must not be used to fill the gap.

The separate [historical market evidence panel](nfl-historical-market-evidence.md)
now supplies exact closing-line ATS and outright cohorts from validated nflverse
archive context. It does not retroactively make the situational service's broader
ATS/totals categories available or influence prediction calculations.

## Availability and presentation

Matchup evaluations use `matched`, `not_matched`, or `insufficient_data`.
Unsupported catalog entries carry an explicit reason. Missing inputs do not
become zero, an opponent advantage, or a new hold on the existing forecast.
Top-five/top-ten overlaps are deduplicated by metric and matchup direction in
the compact highlights; every evaluation remains expandable.

The full 353-item checklist is searchable by label/ID and filterable by category
and status, with 20 rows at a time. Every unsupported item remains visible with
its missing inputs or implementation requirement; retaining a label does not
mean its metric has been implemented. No tally becomes a confidence score.

The sidecar response declares `affects_prediction=false`, predictive weight zero,
and false effects for winner, spread, total, confidence and approval. The UI does
not mutate prediction props. Endpoint tests (excluding global user-activity
bookkeeping) require zero database writes and no
outbound HTTP requests. The sidecar is not called by forecast/research/approval
generation; it cannot add a hold when its own evidence is insufficient.

The panel loads on first expansion, deduplicates repeat opens, supports retry,
and aborts old requests when the selected game changes or the component unmounts.
The authenticated endpoint resolves existing game access before reading its
120-second shared cache. Metric computation uses two grouped queries. Situational
history uses one narrow query for both teams without loading predictions, stats,
or per-game relations. There are no paid AI calls or additional scheduler locks.

## Corrected proxy semantics

The legacy prediction action previously inferred blitz, coverage/secondary,
explosive-play, blocking and pace labels from sack rates, rushing efficiency or
a model-versus-market total difference. New outputs use explicitly descriptive
proxy/context codes, marked diagnostic and non-actionable. Numeric blending
formulas and weights are unchanged.

This intentionally prevents future proxy-only outputs from matching the old
sack/pace-dependent betting rules or validated combinations. New codes are not
aliases for legacy approvals. Legacy code definitions remain readable for audit;
saved raw prediction metadata and existing observations are not silently rewritten.
Fresh predictions are necessary to see new emitted codes after deployment.

## Validation and next phases

Tests cover defensive direction, null/zero handling, ties, partial league coverage,
minimum samples, excluded future/target/same-day games, nonregular phases,
unknown kickoff, query counts, authentication, caching, sequence boundaries,
proxy approval isolation, lazy fetches and compact UI rendering.

```sh
php artisan test --compact tests/Feature/NFL tests/Unit/NFL tests/Feature/Api/V2/NflMatchupSignalEndpointTest.php tests/Unit/Support/NflReasonCodeCatalogTest.php
node --experimental-strip-types --test tests/frontend/*.test.mjs
npm run typecheck
npm run build
```

For synthetic visual QA only, run `node tests/browser/nfl-matchup-signals-preview.mjs`
and open its localhost URL. It never calls production or research providers.

Before assigning any new predictive weights: snapshot inputs and definition
versions prospectively, persist matched observations, then evaluate out-of-time
W–L, ATS W–L–push, totals and price-aware returns separately. Control overlapping
features and multiple testing; historical descriptive records alone do not
establish predictive value. Charting-dependent metrics require validated feeds
and explicit definitions. Do not substitute sack rate for blitz frequency or
generic grades for observed man/zone matchups.
