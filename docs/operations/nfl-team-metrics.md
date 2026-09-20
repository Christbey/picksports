# NFL team metrics integrity and rollout

The NFL page defaults to one season type (regular season). The API still accepts an explicit `season_type=all` for integrations; those results are separate rows, not an aggregate. The calculator also defaults to regular season; the scheduled command explicitly calculates regular season and postseason separately.

## Calculation contract

- W–L–T and games played include ties. Luck uses a half-win for ties.
- Yardage averages exclude missing values, retain reported zero, and store the actual denominator in `sample_sizes`. The UI labels these as reported-game averages.
- Turnover margin requires both fields from both teams for every game. Missing turnover inputs also withhold the dependent predictive rating.
- Missing final scores prevent recalculation; existing rows retain their previous update time. A final status alone is not sufficient evidence of a score.
- One game cannot establish consistency. Home-minus-away margin requires both samples; it is descriptive, not a fitted home-field effect.
- EPA shows eligible non-null offensive and defensive play counts. Those counts do not prove every play was synchronized.
- New sample metadata is nullable on historical rows. The NFL board labels these rows as requiring recalculation instead of inventing zero ties or displaying legacy ratings as verified.

## Deployment follow-up (not performed by local tests)

1. Deploy with `php artisan migrate --force` through the normal release process.
2. Run `php artisan nfl:calculate-team-metrics --season=2026` for both configured season types. This is deterministic and does not call paid AI research.
3. Check that eligible teams have `ties`, `games_played`, and `sample_sizes`. Skipped teams require source-stat repair; do not fill missing values with zero.
4. Check `/nfl/team-metrics` authenticated on mobile and desktop. Recalculate older seasons explicitly if they should use the new presentation. Review downstream predictions before independently authorizing any rerun/release.

The page shows calculation timestamps, not a guarantee that all upstream inputs are fresh. Current Elo opponent rank groups and heuristic predictive/injury/fatigue ratings remain descriptive; these changes do not make them calibrated probabilities or historical as-known snapshots.

Tests: `TeamMetricsIntegrityTest`, existing NFL calculation/snapshot and v2 endpoint contracts, plus `tests/frontend/nflTeamMetrics.test.mjs`. `node tests/browser/nfl-team-metrics-preview.mjs` serves a synthetic responsive QA fixture on port 5193.
