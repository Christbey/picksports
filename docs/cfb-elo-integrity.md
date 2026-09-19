# CFB Elo integrity and canonical release 1.4.0

CFB Elo model `cfb-elo-2.0.0` uses fresh locked team state, atomic paired updates, explicit starting ratings, per-team season initialization, and source-result fingerprints. It retains the existing rating formula while repairing chronology and offseason application. The default first available season starts at 1500; subsequent seasons regress toward 1500 using the configured factor. This is not a claim of calibrated betting accuracy.

## Deployment and repair

1. Apply the integrity migration. It preserves the existing rows and adds versioned active slots. Its rollback deliberately refuses to erase retained history.
2. Run `cfb:sync-season-affiliations --season=YYYY` for every season represented in completed games. The command uses season-specific CFBD membership, rejects ambiguous or incomplete mappings, and marks other local teams explicitly non-FBS. Eligibility reads never write affiliations.
3. Run `cfb:rebuild-elo`. Review its game/team coverage, integrity results, and rating differences. The candidate does not change active ratings.
4. Activate the reviewed candidate with `cfb:rebuild-elo --activate=ID`. Activation locks source rows, verifies their digest, archives old active rows without deleting them, and applies the candidate transactionally. A changed source requires a fresh candidate.
5. Refresh FPI with `--require-complete`, then recompute historical/current metrics. Historical metrics use their season's recorded Elo and do not use current injuries. Recomputed historical summaries remain retrospective, not proof of what was known at past kickoff times.
6. Register canonical release 1.4.0, atomically retire the previous approved CFB pregame release, and run `cfb:run-pregame-pipeline`. There must be exactly one approved pregame release. Preserve prior forecasts and their input snapshots.

The regular Elo command rejects legacy, incomplete, corrected, and out-of-order history rather than silently compounding it. Use candidate rebuild and review for those cases. `--reset` is disabled for CFB. Current-state consistency alone is not sufficient; verify pair completeness, fingerprints, continuity, and season initialization.

## Forecast evidence

Release 1.4.0 identifies the actual Elo source and version. It derives an auditable regressed prior-season baseline before a team's first eligible final game, and rejects stale/legacy/post-cutoff evidence. Snapshot capture and Elo activation share a lock. Missing verified Elo blocks eligibility.

FPI remains the primary spread baseline where both teams have it. Feature coverage diagnostics distinguish executed components from features present only in the legacy engine. An empty injury list does not prove health. The pregame pipeline stops on an unavailable or incomplete injury fetch, and recomputes metrics after Elo and injury updates. Spread/total markets no longer inherit moneyline confidence.

## Validation

`cfb:compare-frozen-baselines --season=YYYY` compares fixed FPI, corrected Elo, and 50/50 baseline margins using frozen pregame evidence. It reports paired sample counts, MAE and bias, not a calibrated ATS probability or automatic promotion. No comparable observations means insufficient evidence, not zero error. Retrospective reconstruction cannot satisfy the calibration trainer's pregame-availability gate.

Preserve the closing-line convention: the last stored pregame quote before kickoff. Do not overwrite immutable historical forecasts with rebuilt inputs or backdate newly imported evidence.
