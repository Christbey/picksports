# NFL prop availability context

NFL rushing attempts/yards and receptions/receiving yards now include a conservative teammate-absence usage adjustment. Confirmed unavailable players are suppressed across all NFL prop markets. Questionable and doubtful statuses do not release workload to teammates.

The adjustment uses current injuries observed within 12 hours and same-season depth charts observed within eight days. It runs only for scheduled games with a future UTC kickoff within seven days; current tables are not used for historical games. It requires a clearly ranked first available player at the same position, complete identity resolution for that position, and at least three historical usage samples for both players. Unlinked depth entries can resolve by a unique ESPN ID without changing roster assignments. Historical usage and injuries must belong to the depth-chart team.

Usage is the last eight completed regular/postseason games from the target season or prior season, strictly before today and the target game. Newly recorded absences must be within 14 days; if the beneficiary already played on or after that injury date, no extra boost is applied. Half the absent player's average attempts/targets is considered for redistribution, capped at a 15% workload increase. The analyzer's existing total context cap remains in place. These are conservative heuristic limits, not calibrated injury effects. Passing and touchdown markets receive availability suppression only.

The `confidence_decomposition.availability` object records the reason, factor, depth ranks, absences, baseline usage, and a versioned fingerprint. Precomputed NFL board entries must match freshly resolved availability context. Old entries without that fingerprint, changed context, or newly unavailable players are withheld until reanalysis.

## Rollout

Deploy the service and analyzer together. Refresh player rosters, depth charts, and injuries through the existing operational commands, then run:

```sh
php artisan sports:analyze-player-props --sport=nfl --season=2026
```

Do not use `--only-missing` for this rollout: existing scores must be recalculated. Existing board/cache deployment procedures still apply. This change does not repair roster sync scheduling or populate NFLverse tables.

## Verification

The focused analyzer, availability, command, and prop API tests pass. A read-only in-memory production evaluation on September 9, 2026 at 6:13 p.m. Central returned a 1.15 workload factor for Stevenson based on Henderson's Out status, suppressed Henderson, and kept Holani neutral because he was not the first available running back. No production predictions were written by this verification.
