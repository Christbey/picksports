# CFB Elo operations

Scope: the current versioned Elo calculation and rebuild path. Reviewed against `CalculateElo`, `CalculateEloCommand`, `RebuildEloCommand`, and `EloRebuildService` on November 17, 2026. No rebuild or production mutation was executed for this review.

## Ownership and evidence

`app/Actions/CFB/CalculateElo.php` defines the Elo model version and source-result fingerprint. `config/cfb.php` owns its tuning defaults. The calculation validates final-game inputs, preserves paired rating changes, and rejects incomplete or changed source history rather than silently compounding it. Season-specific affiliation evidence determines eligible teams. Do not substitute current team affiliation for historical season membership.

The regular `cfb:calculate-elo` command changes rating state. Its destructive `--reset` path is disabled; corrected, incomplete, or out-of-order source history requires a reviewed rebuild candidate. Elo ratings are model inputs, not calibrated spread-cover probabilities.

## Rebuild workflow

A repair request is separate from ordinary game analysis. Inspect the command definitions and use the authorized target database before performing repair work:

```bash
php artisan cfb:rebuild-elo --help
```

Running `cfb:rebuild-elo` without activation creates and persists a candidate from source results without replacing active rating history. Review the candidate's game/team coverage, exclusions, rating differences, source seasons, and input digest. It is a database write even though live ratings remain unchanged.

For an explicitly approved candidate, `cfb:rebuild-elo --activate=ID` replaces the active rating version transactionally while retaining earlier rating rows. Activation locks source data, verifies the candidate digest, and refuses a candidate whose source inputs have changed. Create a fresh candidate after source corrections rather than bypassing the digest check. Preserve the candidate ID and prior active version as repair evidence.

The command is not a read-only connection test. Do not activate a candidate, synchronize affiliations, register a calculation release, or regenerate forecasts to answer an ordinary matchup question.

## Downstream interpretation

Verify the active calculation release and frozen input provenance separately from the active Elo state. A repaired historical rating does not prove that a forecast possessed that information before kickoff. Do not overwrite immutable forecasts, backdate reconstructed inputs, or approve a release simply because a rebuild succeeded.

See [model lifecycle](model-lifecycle.md), [CFB decision policy](cfb-decision-policy.md), and [CFB football signals](cfb-football-signals.md) for prediction ownership and interpretation. Production connection procedures are in [production access](operations/production-access.md).

## Schema release sequence

`2026_09_19_180000_version_cfb_elo_integrity` adds the version-aware unique key and supporting team index while retaining legacy uniqueness. Deploy the `active_slot`-aware readers and writers before applying `2026_11_19_220000_contract_cfb_elo_legacy_uniqueness`. The contract verifies the replacement indexes and valid active-slot values before retiring the obsolete game/week unique indexes. It also works when an earlier deployment already removed those indexes. Retained rating versions prevent restoring legacy uniqueness, so rollback requires a reviewed forward repair; the contract migration refuses `down()`. No rows are deleted.
