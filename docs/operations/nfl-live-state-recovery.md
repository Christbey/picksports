# NFL live-state recovery

The September 13, 2026 audit found that the production application uses America/Chicago, but NFL scoreboard and detail schedules began at 17:00. Noon games were therefore left scheduled until an explicit scoreboard recovery. Move both windows to 06:00–02:00 to cover international, afternoon, and evening kickoffs. The scoreboard runs every five minutes; completed-game details run every thirty minutes.

A separate confirmed overwrite path exists in the nflverse schedule import: unscored rows previously replaced an existing live/final state with scheduled and cleared scores. JSON imports had the same risk. ESPN status resolution protected status but could still apply a weaker payload's zero scores. NflGameStateGuard now preserves the NFL lifecycle fields together when an incoming status regresses, and retains populated state when incoming fields are null. Enrichment remains allowed, as do same-status score corrections. Halftime and end-period are equivalent live phases so resumed play is accepted. These protections apply to the audited Eloquent intake paths, not arbitrary direct SQL updates.

The audit proves the overwrite vulnerabilities; it does not establish that a particular historical import caused this incident. The late scheduler window is independently verified.

Recovery:

```sh
php artisan espn:sync-nfl-games-scoreboard 20260913 --sync
php artisan espn:sync-nfl-game-details --lookback-days=1 --limit=16 --sync
```

Verify game scores/status against ESPN, non-null prediction graded_at for completed games, player-stat rows, and separate live_updated_at fields for ongoing games. Finalization grades completed games; NFL details sync retries prop grading after player stats arrive. Original predicted_spread, predicted_total and win_probability must not change during recovery. Missing original prop records cannot be reconstructed from current odds.

Regression coverage: NflStaleGameStateTest, NflGameStateGuardTest, ImportNflverseSchedulesCommandTest, NflDaytimeSyncTest, existing ESPN status, scheduler, and finalization tests.
