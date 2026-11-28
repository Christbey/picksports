# Women's basketball play observations

The WNBA `espn:sync-wnba-plays {eventId}` command dispatches the existing play-sync job. A successful dispatch message alone does not establish that a worker fetched or stored any observations. Use the existing queue and source checks before relying on a refresh.

WCBB's default ESPN service deliberately disables play acquisition. This repair does not enable it. Local tests inject a synthetic WCBB service solely to check compatibility of the dormant writer with the existing schema; they do not establish an active WCBB feed.

The basketball DTO produces `espn_play_id`, `shooting_play`, `made_shot`, `assist`, `is_turnover`, and `is_foul`. The women's play models admit those actual columns and cast the five flags as booleans. ID-only or flag-only corrections can therefore participate in the existing unchanged-feed comparison. Explicit zero scores and false flags remain observations rather than missing values.

The registered play resources keep their existing `espn_id` and `scoring_play` wire keys. Those aliases read `espn_play_id` and `made_shot`; a retained nullable play identity remains null. This source review did not establish a current HTTP plays endpoint, and the resource checks must not be described as an authenticated endpoint test.

No historical rows are backfilled. Previously discarded IDs or flags remain unavailable until an authorized supported refresh supplies them; the repair cannot infer missing history. No schema, factory mapping, acquisition flag, model mathematics, or approval setting changes.

Local synthetic controls cover the active WNBA CLI/job path, DTO-to-row-to-resource values, corrected observations, true-to-false refresh, zeros, same-feed replay, and rollback when a second replacement write throws. Separate controls cover WCBB's disabled default, injected compatibility, and both resource aliases. The rollback evidence concerns the existing same-connection replacement transaction; it does not certify arbitrary foreign observer effects, provider availability, queue liveness, or historical reconstruction. Production deployment and any native or full-suite qualification remain separate from those focused SQLite controls.
