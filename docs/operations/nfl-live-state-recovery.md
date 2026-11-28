# NFL live-state recovery

Scope: recover a verified stale NFL scoreboard/details state without changing original pregame forecasts. Check the actual schedule and timezone in `routes/console.php` and the running environment before attributing missing updates to scheduler timing. A local code fix is not evidence that production is running that revision.

A separate confirmed overwrite path exists in the nflverse schedule import: unscored rows previously replaced an existing live/final state with scheduled and cleared scores. JSON imports had the same risk. ESPN status resolution protected status but could still apply a weaker payload's zero scores. NflGameStateGuard now preserves the NFL lifecycle fields together when an incoming status regresses, and retains populated state when incoming fields are null. Enrichment remains allowed, as do same-status score corrections. Halftime and end-period are equivalent live phases so resumed play is accepted. These protections apply to the audited Eloquent intake paths, not arbitrary direct SQL updates.

Before recovery, distinguish provider/source lag, failed scheduling, worker backlog, and a status regression. Inspect stored timestamps and the last successful job; do not infer the cause from a stale screen alone.

## Authorized recovery

These commands fetch provider data and write records. They are maintenance operations, not prerequisites for an ordinary analysis answer. Replace the date with the target slate and keep the scope bounded:

```sh
php artisan espn:sync-nfl-games-scoreboard 20260913 --sync
php artisan espn:sync-nfl-game-details --lookback-days=1 --limit=16 --sync
```

Verify game scores/status against the fetched provider payload, non-null prediction graded_at for completed games, player-stat rows, and separate live_updated_at fields for ongoing games. Finalization grades completed games; NFL details sync retries prop grading after player stats arrive. Original predicted_spread, predicted_total and win_probability must not change during recovery. Missing original prop records cannot be reconstructed from current odds.

Regression coverage: NflStaleGameStateTest, NflGameStateGuardTest, ImportNflverseSchedulesCommandTest, NflDaytimeSyncTest, existing ESPN status, scheduler, and finalization tests.
