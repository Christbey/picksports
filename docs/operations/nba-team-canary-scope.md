# NBA team production canary scope

Capture and structured research require both their existing feature flags and an explicit unexpired team canary policy:

```dotenv
NBA_INJURY_CANARY_TEAM_IDS=
NBA_INJURY_CANARY_EXPIRES_AT=
```

IDs are internal `nba_teams.id` values, not ESPN IDs. At most two positive integer IDs are admitted. Empty, malformed, overly broad, timezone-free or expired policies admit nobody. The expiry is an original ISO-8601 instant with offset. Expiry automatically returns subsequent acquisitions to the original NBA path and removes structured admission; evidence/history remains intact. Existing in-flight acquisitions may finish, but expired evidence cannot be promoted into new structured research.

Unselected NBA teams use the existing injury action and do not reserve acquisition sequences or create immutable observations. Games require both participating teams selected, so a matchup with only one canary team retains its previous manual-document research behavior. The explicit NBA source-only command refuses games outside this scope. The structured adapter itself also checks the policy when called directly. NFL files and behavior are unchanged.

The shared analysis scheduler/publisher remain off. No source-refresh schedule or paid review dispatch is added. Existing provider ingestion schedules remain unchanged and may capture selected teams through their existing jobs while the canary policy is valid; research assessment is still explicit/manual.

Deploy the scope code with both feature flags off first. Verify that empty policy denies all teams. Only then configure the chosen two-team policy/expiry and enable the two new feature flags, preserving every other Cloud setting. Register only those teams' `structured_espn` sources and acquire/assess one verified pregame NBA matchup. Keep a record of source times, receipts and side effects.

For rollback set `NBA_RESEARCH_SOURCE_REFRESH_ENABLED=false` and `NBA_INJURY_OBSERVATIONS_ENABLED=false`, rebuild/redeploy through the established Cloud process, and preserve the allowlist/history, source records and evidence. Expiry independently denies subsequent admission without a new deployment. Never reset counters, delete immutable rows, drop tables, purge shared queues or change NFL settings as part of rollback.
