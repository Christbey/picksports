# NFL simplification: preserve contracts, remove repeated work

## First slice: team history

The prediction generator previously repeated season queries, eager loads, home/
away stat matching, rate calculations, and averaging across rolling efficiency,
opponent-adjusted efficiency, total environment, and line matchup profiles.

`NflTeamHistory` now owns that work for one target game. It uses Eloquent,
explicit column selection, and eager-loaded `teamStats`, loading both participating
teams together. Historical Elo lookup remains a supplied dependency, preserving
the generator's existing date policy. Four existing profile methods delegate to
this service; their output field names, rounding, signs, sample semantics, and
fallback behavior remain unchanged.

The service is recreated for each prediction. Do not register it as a singleton,
persist its cache across queue jobs, or retain it between slate updates.

Characterization fixtures were captured from the pre-refactor implementation:

| Scope | Before queries | After query ceiling |
| --- | ---: | ---: |
| Week 1 with prior-season fallback | 18 | 4 |
| Current-season profiles for both teams | 22 | 8 |

These are fixture-level measurements for the four profiles, not full-request or
production-runtime claims. Tests compare the complete serialized profile output
and verify that repeated reads within one prediction issue no further queries.
They cover reversed home/away roles, current/prior seasons, season-type scope,
missing stats, recent-game windows, and same-day/future-game exclusion.

The generator shrank from 6,173 to 5,836 lines. Including the new 184-line service,
this slice removes 153 net implementation lines. It does not merely distribute
the same duplicated code among new files.

## Second slice: analysis, QB summaries, release candidates, forecast order

- `applyAnalysisLayer` now builds full reason evidence once, after trust adjustment.
  The former first pass existed only to supply key-number flags; a small shared,
  database-free calculation now supplies those flags. The reason catalog is
  expanded once after specialist codes have been merged.
- `NflOddsHistory` is shared by the generator and specialist analysis. It reads
  a count/MAX(id) boundary and hydrates at most first, last and middle snapshots
  using Eloquent (at most four SQL reads, at most three payloads). Concurrent
  appended snapshots are excluded until the next prediction. Equal capture
  timestamps now have deterministic ID ordering. A composite lookup index is
  included in a separate migration; no existing index is removed.
- Point-in-time injury state is reused within one forecast; a new generation
  clears its context. Existing source/as-of/availability rules remain intact.
- `NflQuarterbackStatsSummary` handles both QB sources in one traversal. Source
  queries still enforce their original career, player and date scopes. Local
  history retains `current_team_games`; nflverse still projects unavailable sacks
  as zero. This cleanup does not certify that source default as measured data.
- Release recording and candidate-key reporting share one strict candidate filter.
  Data completeness, model consensus and immutable pregame release eligibility
  remain distinct. A model candidate is not automatically a released wager;
  quote, snapshot, timing, idempotency and tracking protections are retained.
- `calculateForecast` provides one explicit ordered sequence, and the repeated
  spread-to-probability expression has one implementation. Regression tests lock
  all 15 existing stage calls, their intermediate inputs and total-only,
  probability-only and metadata-only behavior. No stages, weights or clipping
  boundaries were deleted or reordered to make the code shorter. Removing model
  adjustments remains an empirical model-validation decision, not a refactor.

New services are deliberately narrow: no generic pipeline framework, stage
registry, or one-class-per-rule structure. The generator is now 5,740 lines;
QB identity resolution and the extensive reason-code policy remain future work.

Verification: combined NFL feature tests, QB summary unit tests and canonical
football lifecycle tests pass (506 tests, 2,761 assertions). Integration tests
exercise 1,000 odds snapshots with four queries/three hydrated payloads, prove
repeated injury reads add no queries, and verify a new forecast refreshes its
evidence. Formatting and whitespace checks pass. The code and ordered-history
index migration remain local; production deployment/migration was not performed.

## Remaining work

1. Extract QB and injury input resolution into focused services with dated input
   contracts. Consolidate their repeated queries before changing their formulas.
2. Separate forecast arithmetic from reason-code formatting and bet eligibility.
   The generator still contains several thousand lines and is not fully refactored.
3. Consolidate shared historical record queries without merging intentionally
   different regular-season, postseason, venue, or opponent scopes.
4. Keep resource/DTO payload contracts stable; Vue should render prepared data,
   not independently recalculate probabilities or choose historical samples.
5. Remove an old path only after callers, scheduled commands, and characterization
   tests prove it is redundant. Avoid adding traits or interfaces solely to make
   a file look shorter. Use Eloquent scopes for reused domain filters, eager
   loading for relationships, and explicit request/job-scoped caches.

Model-policy changes and structural refactors are separate concerns. The EPA and
spread safeguards are documented in `nfl-spread-integrity.md`; the team-history
cleanup does not additionally change their calculations. In particular, totals
currently include all season types while the other profiles use regular-season
history. This refactor preserves that distinction instead of silently changing
the model during a cleanup.
