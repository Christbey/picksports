# NFL team metrics integrity and rollout

The NFL page defaults to one season type (regular season). The API still accepts an explicit `season_type=all` for integrations; those results are separate rows, not an aggregate. The calculator also defaults to regular season; the scheduled command explicitly calculates regular season and postseason separately.

## Calculation contract

- W–L–T and games played include ties. Luck uses a half-win for ties.
- Yardage averages exclude missing values, retain reported zero, and store the actual denominator in `sample_sizes`. The UI labels these as reported-game averages.
- Turnover margin requires both fields from both teams for every game. Missing turnover inputs also withhold the dependent predictive rating.
- Missing final scores prevent recalculation; existing rows retain their previous update time. A final status alone is not sufficient evidence of a score.
- One game cannot establish consistency. Home-minus-away margin requires both samples; it is descriptive, not a fitted home-field effect.
- EPA shows eligible non-null offensive and defensive play counts. Those counts do not prove every play was synchronized.
- NFL play EPA attributes cumulative scoreboard changes between scoring events to the scoring event itself; administrative rows may already carry the updated score. The calculation requires a complete, ordered game feed, including scoring flags and periods.
- Continuation EP and next-score fallback targets stop at halftime and the end of regulation; ordinary quarter breaks preserve continuation. Fallback next-score targets include the current play when it scores. Historical baseline selection remains unchanged. The same-game fallback is descriptive and is not a validated prospective EPA model.
- Before any explicitly authorized EPA rebuild, compare a read-only replay against stored play EPA, then preview corrected team averages with matching season, completed-game filters, and baseline settings. Rebuilding play EPA also requires recalculating team metrics; neither occurs automatically when code changes.
- New sample metadata is nullable on historical rows. The NFL board labels these rows as requiring recalculation instead of inventing zero ties or displaying legacy ratings as verified.

## Deployment follow-up (not performed by local tests)

1. Deploy with `php artisan migrate --force` through the normal release process.
2. Run `php artisan nfl:calculate-team-metrics --season=2026` for both configured season types. This is deterministic and does not call paid AI research.
3. Check that eligible teams have `ties`, `games_played`, and `sample_sizes`. Skipped teams require source-stat repair; do not fill missing values with zero.
4. Check `/nfl/team-metrics` authenticated on mobile and desktop. Recalculate older seasons explicitly if they should use the new presentation. Review downstream predictions before independently authorizing any rerun/release.

The page shows calculation timestamps, not a guarantee that all upstream inputs are fresh. Current Elo opponent rank groups and heuristic predictive/injury/fatigue ratings remain descriptive; these changes do not make them calibrated probabilities or historical as-known snapshots.

Tests: `TeamMetricsIntegrityTest`, existing NFL calculation/snapshot and v2 endpoint contracts, plus `tests/frontend/nflTeamMetrics.test.mjs`. `node tests/browser/nfl-team-metrics-preview.mjs` serves a synthetic responsive QA fixture on port 5193.


## Custom EPA quarantine

While `nfl.predictions.true_epa.custom_epa_quarantined` is true (default), team metrics skip custom EPA scans and preserve existing custom aggregates without refreshing them on normal metrics refresh. Model access and API serialization also null historical custom EPA values; raw stored history is retained. The nullable API keys remain compatible. The NFL board removes custom EPA and defaults to scoring margin.

NFL custom EPA baseline, blend-calibration and incremental tasks are not scheduled during quarantine. Manual `nfl:calculate-play-epa` exits without reading or writing plays. Existing prediction guards remain. Separate nflverse EPA matchup evidence and prospective prop experiments are unchanged. Deployment is required for production effect.

Admin-only `/admin/nfl-experimental-ratings?season=2026` displays raw historical custom EP aggregates with explicit experimental, source, quarantine and unknown-calculation-time labels. It performs a bounded read of at most 32 aggregate rows, never a play scan or generation job. Public readers continue to return null.


## Metric capture receipts (2026-11-28)

New metric captures select the regular-season row using the futures reader's calculation-date/id ordering. A nullable versioned observation descriptor records independent actual UTC observation time (including microseconds), the requested cutoff, source identity and selected value hash. The descriptor checksum/key checks provide integrity and shape consistency; they are recomputable and are not authentication or a tamperproof database log.

The omitted cutoff defaults explicitly to the current whole second for the existing TIMESTAMP(0) storage carrier. Explicit nonzero fractional cutoffs fail, including for an empty season. The descriptor retains UTC6 observation time separately; an observation later within the same second is withheld from an earlier cutoff. The stored carrier uses the declared application timezone, checked against the explicit UTC cutoff. Mixed application-timezone reconfiguration is not certified by this change.

A requested old `--date` does not make newly observed current values historically available. Strict point-in-time readers require validated receipt lineage and exclude observations after their evaluation instant. A first receipt is immutable for its capture/reconstruction mode, team, season and cutoff: an exact retry preserves it, while changed source/values refuse instead of overwriting it. Old descriptor-less rows are neither backfilled nor relabeled; non-strict descriptive reads can still return them as `legacy_unverified`.

`--backfill-records` keeps the existing historical calculator and is explicitly `historical_reconstruction`, performed later, rather than a contemporaneously observed forecast. Its mode is separate from `current_capture`. A retained mutable current-metric fallback is labeled `current_fallback_mutable_unverified`, including when the point-in-time caller supplies no as-of date. Projections and betting/backtest JSON reports carry metric lineage and mode counts. These labels certify neither all other inputs (including results and odds) nor complete historical forecast availability, calibration or betting approval.

The additive migration must precede application use of the descriptor column; it adds nullable JSON and does not rewrite historical rows. Isolated native verification preserved a synthetic legacy row's complete original field representation byte-for-byte through migration383→384 with observation NULL. The same31 scoped PHP cases passed154 assertions across disjoint8/8/8/7 MySQL shards at128MiB with canonical identity reconciliation. This is a local owned-fixture result, not production deployment, a live provider refresh, global memory bound, overlapping-writer/connection-drift proof or a whole-application forecast certificate.
