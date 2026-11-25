# CBB play EPA recovery

CBB core plays paginate at 300 events and represent team IDs as `$ref` URLs. The CBB service consumes all pages (at most ten), validates counts and distinct play IDs, and preserves stored data on incomplete pages, foreign matchup teams, or a completed feed that does not reach the stored final score.

The CBB DTO resolves numeric team references without additional requests. A feed team identifies the event actor; offense attribution is limited to shooting plays, turnovers and offensive rebounds. Defensive actors, fouls and administrative events remain unassigned. Missing attribution is never inferred from player names. This is the existing descriptive play-EPA estimator, not a calibrated betting probability. Its expected-points fallback can use within-game states when no prior-season baseline is available.

Preview one bounded batch without provider calls or writes:

```bash
php artisan cbb:repair-play-epa --limit=10
```

The default season uses `BasketballSeason::forDate()`. An explicit `--season` supports historical recovery. Apply only the reviewed batch:

```bash
php artisan cbb:repair-play-epa --limit=10 --apply
```

`--game-id` scopes a canary. `--through-game-id` bounds disjoint batches. `--after-game-id` resumes past an investigated failure; skipped failures remain unrepaired and must be reported. The limit is 1–50. Candidates are completed games with both team box scores and no calculated play EPA, including games with no stored plays. Re-running excludes successfully repaired games. No predictions, ratings, approvals or settings are regenerated. Only existing team metric rows' three play-EPA fields and update timestamps are refreshed, with the ordinary analytics season-type filter. Only complete team-stat games are included; their count must match the stored metric games played, and both teams must have EPA in every included game. Aggregates remain unavailable until this full coverage gate passes. API metric cache segments are invalidated after a batch.

Each game fetches a summary and complete play pages through the existing ESPN service. EPA updates are batched; replacement and calculation take the normal per-game database lock. Failed/inconsistent feeds preserve stored plays. Changes in a later provider feed reset derived play EPA through the existing sync lifecycle; identical refreshes preserve it. The existing incremental CBB EPA schedule and normal team-metric refresh continue to maintain new data; this recovery command does not add a schedule or start bulk jobs automatically.

Validate with `tests/Feature/CBB/RepairPlayEpaTest.php`, the CBB/NFL sync tests and shared atomic-ingestion tests. After deployment, start with one game and verify full play count, offense attribution, non-null play EPA and both teams' Net EPA before increasing batch size. Track repaired/failed games and remaining coverage; a successful command is not proof of complete league coverage.

After all source batches, `php artisan cbb:repair-play-epa --refresh-metrics --apply` refreshes covered existing team EPA rows without any provider requests. Rows whose stored game count disagrees or whose game coverage remains incomplete have the three EPA fields held at null, including any earlier partial aggregate.

For a reviewed recovery manifest, use `--game-ids=123,456 --limit=2 --apply`. This exact allowlist excludes newly eligible games inside the same ID range. Range bounds alone are not a fixed recovery manifest; do not use them to enforce an approved ID list.

## Consistent samples and normal ingestion

`Services/CBB/TeamMetricSample` owns the validated game set used by both the normal CBB team metric calculator and the EPA refresher. It retains the existing reconciled-box-score, real-event, season-type and completed-date rules. Coverage requires offensive EPA from both matchup teams in every included game; the normal metric writer holds EPA at null for a partial sample, just like the refresher. Sample-count mismatches require the ordinary CBB metric calculator to rebuild that exact team's row before refreshing EPA.

Completed-game CBB play ingestion computes derived play EPA after a successful validated replacement when unscored plays with both offensive teams are present. An unchanged, already-scored refresh retains its existing EPA and play identities. This closes the gap where a detail refresh replaced stored plays but left their EPA awaiting the small incremental backlog job. Live/incomplete or unattributable feeds are not forced into EPA eligibility.

For recovery, complete stored feeds can be rescored without provider requests after revalidating first/last state, final scores, distinct play identities and both matchup teams under the existing per-game lock. Preserve invalid or unavailable provider feeds; do not assign opponents from names, swap score columns by inference, or substitute a box-score proxy for play EPA.


If a complete feed's scoreboard disagrees with the final, CBB may reconstruct its score state from explicit scoring-event team IDs and 1/2/3-point values. This requires a zero-score first-half start at 20:00, a terminal 0:00 state, known matchup scorers for every scoring event, and exact reconciliation of both accumulated final scores. Unknown scorers, missing scoring points, incomplete sequences and foreign matchup teams remain held. The original scoreboard hash is logged when reconstruction succeeds; this is deterministic score accounting, not an inferred home/away swap.
