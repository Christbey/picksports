# NFL game simulation

Scope: implemented score-distribution preview and shadow interfaces in this checkout. Reviewed against `app/Console/Commands/NFL/SimulateGameCommand.php` on November 17, 2026. This does not establish deployment or probability calibration.

## Purpose and boundaries

The simulator uses a frozen pregame forecast and same-model paired historical score residuals. It is a shadow research tool; simulated frequencies are uncalibrated and do not authorize bets. A `pre_market` preview removes the recorded direct blend only, not every indirect market effect. Keep source forecast/quote timestamps, model family, seed, configuration, and output hash with the result.

## Commands and side effects

Preview, replay, and report read stored data; `--record` writes a pregame shadow observation and `--grade` writes evaluations. Those writes require explicit scope. No provider refresh or deployment is required for preview. Check `php artisan nfl:simulate-game --help` before use.


- `php artisan nfl:simulate-game GAME_ID`: read-only preview; default 10,000 draws. Requires a source forecast observed within 36 hours and at least 200 same-family historical games. History is frozen at the original forecast generation time, not the later preview request. Actual kickoff from the provenance resolver governs temporal checks; the recorded source start is retained in its original snapshot for audit.
- `--basis=pre_market`: use the source snapshot's recorded pre-market outputs. Missing outputs cause a hold. This removes the direct market blend only and does not certify independence from all market effects.
- `--provisional`: explicitly opt into unfitted normal assumptions for a read-only preview when history is insufficient. This is not a validated betting probability or a replacement for the historical fitter. Provisional runs cannot be captured.
- `--record`: capture a fitted observation only when `NFL_GAME_SIMULATION_CAPTURE_ENABLED=true`. Capture remains disabled by default. The first observation per game/basis/engine version is retained; a different later request cannot replace it. Identical repeated requests reuse it.
- `php artisan nfl:simulate-game --replay=SNAPSHOT_ID`: reproduce the frozen inputs, seed and quotes and verify the output hash without writing.
- `php artisan nfl:simulate-game --grade --limit=100`: grade mature final observations, skipping already graded records. The first evaluation is immutable; later official corrections require separately versioned review.
- `php artisan nfl:simulate-game --report --limit=1000`: aggregate bounded observations separately by engine version, forecast basis and source model version. Report paired point error, three-outcome Brier scores, 90% interval coverage and market-specific push/line-error summaries. A quote count is not an independent game count.

Replace GAME_ID/SNAPSHOT_ID with existing identifiers. Preview failures return an explicit hold or input error. Stale/missing quotes produce no market result rather than fabricated lines. A changed listed QB that conflicts with the frozen model input holds the preview; ordinary projected starter wording alone does not. No commands are automatically scheduled and no public game/prediction API contract changes.

The historical residual corpus and compact distributions are stored, not individual simulation paths. Existing production predictions, approval states and playoff simulation behavior are not modified. Prospective calibration, an actual historical performance study and any promotion require a separate review after sufficient suitable observations exist. A drive/play engine is not part of this interface.
