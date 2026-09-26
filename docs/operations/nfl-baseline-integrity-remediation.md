# NFL baseline integrity remediation — September 21, 2026

For measured before/after effects, see the [integrity change impact review](nfl-integrity-change-impact.md).

Status: code fixed and tested locally; not deployed. The production archive
planner was run read-only in memory. No historical odds, ratings, predictions,
grades, canonical kickoff times or configuration were changed.

Verification: **620 tests / 3,152 assertions passed**, covering the NFL feature
suite, canonical football lifecycle, event identity dual writes, QB summary and
Elo checks across NBA, MLB, CBB, WCBB and CFB. Pint and diff checks passed.

## Local fixes

- `ImportNflverseSchedulesCommand` negates nflverse's home-margin spread for the
  home sportsbook handicap, preserving the opposite away handicap and each
  side's price. Raw source spread remains in metadata.
- Source kickoff is parsed in `America/New_York` then converted to the app's
  storage timezone. Daylight/standard time are covered. Missing kickoff time
  no longer creates a synthetic midnight closing snapshot.
- New snapshots explicitly record the source timezone, original source date/time,
  spread conventions, `normalization_version=nflverse_schedule_v2`, and that
  archive capture time is synthetic rather than a live observation.
- Reimports of games with old/unversioned nflverse archives are blocked before
  changing the game or its evidence, including during dry-run planning. The
  command reports these games and exits unsuccessfully. Unrelated rows may still
  import; this is a per-game safeguard, not an all-file transaction.
- `CalibrateSpreadCommand` uses only strictly prior-date Elo, excludes missing
  ratings rather than silently inserting 1500, restricts evaluation to regular
  season final games with scores, and matches forecast spread caps/one-decimal
  output. Elo history is loaded once for all candidates. Invalid/nonfinite grids,
  nonpositive steps and grids above 1,001 candidates are rejected. It labels the
  best result as in-sample, not held-out validation, and never promotes settings.
- The shared Elo calculator credits ties as 0.5 to both teams and skips final
  games with missing scores. Non-tied outcomes are unchanged. Cross-sport Elo
  tests are included because this is shared code.

## Production dry-run result

The new read-only planner inspected 4,630 nflverse snapshots from 2009–2025:

- 4,625 require home/away spread sign correction.
- Five zero-line snapshots need provenance/timezone review but no sign change.
- All 4,630 legacy snapshots require original schedule evidence to establish
  corrected kickoff times; synthetic capture timestamps are not sufficient.
- No ambiguous/conflicting source records were reported in this run.

[Saved production summary](../../outputs/nflverse-archive-repair-plan-2026-09-21.json).

After deployment, the summary is reproducible with:

```sh
php artisan nfl:plan-nflverse-archive-repair --from-season=2009 --to-season=2025
```

Add `--detailed` to include original payloads/context/timestamps, original payload
hashes and proposed spread payloads. The command has **no apply mode**. Proposed
payloads are a review manifest, not a complete executable repair: they do not
claim to normalize timestamps or refresh provenance by themselves.

## Remaining production work — requires a separately reviewed repair

1. Obtain and retain the original schedule input; match provider game ID, teams,
   season and scores. Confirm Eastern kickoff conversion against canonical event
   identity before proposing time changes. Reject ambiguous matches.
2. Back up exact selected rows and dependent game/market/identity records. Build
   an explicit ID/hash manifest; retain original evidence and correction reasons.
3. Implement and test transactional, idempotent correction with compare-before-
   write checks and a rollback path. Review game `odds_data`, snapshots, canonical
   starts and dependent quotes rather than blindly rerunning the importer.
4. Inventory historical grades affected by reversed lines; version any corrected
   grades without overwriting original predictions or losing original evidence.
5. Rebuild an Elo candidate chronologically from verified seeds and offseason
   rules. Correcting the tie formula alone does not undo downstream rating drift;
   do not edit a single old Elo row or run a destructive reset as a substitute.
6. Rerun matched historical comparisons, then explicitly approve deployment/data
   correction. Keep this separate from tuning prediction weights.

The original [all-history audit](nfl-all-history-baseline-audit.md) remains a
record of the inspected state. Its results are not retroactively relabeled as
performance of these local repairs.
