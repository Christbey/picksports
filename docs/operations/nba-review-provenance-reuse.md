# NBA identical-content review reuse candidate

This candidate adds `NBA_REVIEW_PROVENANCE_REUSE_ENABLED=false` by default. It is prepared locally on top of production release `2dbf544c`; it has not been deployed or enabled. NFL fingerprints, review ownership, schedules and compatibility policy are unchanged. No source refresh or AI automation is added.

## Behavior and limits

A newly paid, applicable NBA review can record its original research packet in the append-only `nba_review_revalidations` table. When an explicit reconciliation sees a new observation with identical archived content and claims, it can append another provenance association to that same immutable review instead of scheduling another purchase. Every association retains the full evidence packet, its exact desired fingerprint and the versioned comparison hash.

The original review continues to own its AI generation, paid timestamp, usage, cost, output and expiry. A newer source expiry cannot extend paid validity. The game page keeps the original `reviewed_at`; it does not present a refreshed observation as a new AI interpretation. Reading a game only looks up already-recorded associations and cannot create one or call a provider. The deterministic decision policy is still applied to freshly built forecast/approval inputs.

Reuse requires all of the following:

- NBA only, the new flag enabled, additive storage installed, and completed work with an unexpired original current review.
- Current, unexpired `game_research_summary_v1` evidence, with `ready` or `partial` status and a nonempty source collection. Partial research stays partial; unchanged missing lineup confirmation is not filled in.
- Every source has an ESPN or PickSports provider, parser version and nonempty archived-content hash. Manual/PDF sources continue through the existing path and cannot use this optimization.
- Exact original forecast/approval/quote input hash and provider/model/prompt/schema/policy execution hash.
- Identical claims, player/event/team identity, authority/source identity and URL, coverage, unresolved questions, parser versions and raw content hashes. Unknown fields are retained in comparison.

Only known receipt/snapshot IDs, generated report/evidence hashes and observation/publication/check/expiry timestamps are excluded from the comparison. References to NBA immutable snapshots/entries are compared by content identity; stored game references and other reference types remain material. Full original references and timestamps are retained in each association.

This is deliberately conservative. Changed raw JSON content still invalidates reuse, even if the change appears cosmetic. It does not implement broad claim-only deduplication. Existing paid reviews without an initial provenance association are not retroactively enrolled. A later paid revision of an expired fingerprint receives its own association; old associations are never overwritten.

## Concurrency and recovery

Reconciliation holds the existing work-item row lock while comparing and appending lineage. The existing full fingerprint remains authoritative; it is not replaced with a weaker NBA fingerprint. A unique `(work item, desired fingerprint, original review)` key makes repeated reconciliation idempotent while allowing a legitimate new paid review after expiry. Pending/running work cannot reuse a result through this path; existing lease invalidation and paid-response crash recovery remain unchanged.

Switching the flag off restores the exact fingerprint lookup path. Already-reused work can then appear outdated and require ordinary reconciliation. Disabling reuse does not delete lineage, refund or erase usage, revive an old approval, or extend expiry.

## Rollout steps — not executed

1. Review the isolated candidate diff and test evidence. Deploy only its explicit allowlist on top of `2dbf544c`, with the new key absent or `false`. Keep `GAME_ANALYSIS_SCHEDULE_ENABLED=false` and `GAME_ANALYSIS_PUBLISHER_ENABLED=false`. Do not renew the existing two-team canary window implicitly.
2. Apply the additive migration with the existing deployment command, `php artisan migrate --force`. Verify `2026_11_22_010000_create_nba_review_revalidations` is recorded and no candidate migration is pending. Confirm NFL health, source/review ownership and all existing production settings are unchanged.
3. Before activation, select an explicitly authorized, future NBA matchup with both teams admitted by the unexpired two-team gate. Verify current source approval, complete acquisition receipts, fresh research and an eligible stored forecast. Keep projected starters and availability conflicts visible. Record the original review and accounting baseline. An absent forecast is a failed prerequisite, not permission to generate one.
4. Only after review, set `NBA_REVIEW_PROVENANCE_REUSE_ENABLED=true` in the production Laravel Cloud environment and apply configuration through the normal deployment. Leave both automation keys false. Do not change NFL or widen/extend source scope as part of this change.
5. Use the single-game service calls below in an authenticated, bootstrapped production Laravel console for the explicitly reviewed internal game ID. The existing CLI batch commands have no game-ID filter, so `--limit=1` is insufficient to guarantee selection of this matchup. The first eligible review creates one generation, one immutable revision and its initial provenance association. This can incur one paid request and requires the separately authorized canary budget.
6. After a genuine newly successful source observation and explicit source-only research assessment, reconcile only that same game. If archived bodies and semantic inputs match, expect a second provenance association and no second generation/revision, no new paid usage and unchanged original expiry/reviewed time. Changed content must take the ordinary bounded-work path instead; do not alter inputs to force reuse.
7. Read the game page and compare the accounting ledger, original revision, new association, effective scope and NFL baseline. Test source revocation and original expiry in nonproduction. Keep automation off until these production results and overnight acquisition coverage are reviewed.

For steps 5 and 6, set `$gameId` to the reviewed future internal game ID before running this PHP in the bootstrapped Laravel console. This is a write/possible paid operation, not a read-only preflight:

```php
$game = App\Models\NBA\Game::findOrFail($gameId);
$reuse = app(App\Services\Predictions\Review\NbaReviewReuse::class);
$evidence = app(App\Services\Research\GameResearchService::class)->forGame($game, 'nba');
if (! $reuse->enabled('nba')
    || ! app(App\Services\NBA\Injuries\InjuryCanaryScope::class)->allowsGame($game)
    || ! $reuse->eligible($evidence)
    || ! app(App\Services\Predictions\PredictionReviewInputBuilder::class)->forGame($game, 'nba')) {
    throw new RuntimeException('Single-game canary prerequisites failed');
}
$work = app(App\Services\Predictions\Review\PredictionReviewCoordinator::class)
    ->reconcileGame($game, 'nba', $evidence);
if (! $work) {
    throw new RuntimeException('Selected game is not eligible for review');
}
$result = app(App\Services\Predictions\Review\PredictionReviewRunner::class)->run($work);
// First paid review: succeeded. Identical-content revalidation: not_due, work still succeeded.
dump(['game_id' => $game->id, 'work_id' => $work->id, 'result' => $result,
    'state' => $work->fresh()->state, 'reason' => $work->fresh()->reason_code]);
```

Rollback: set only `NBA_REVIEW_PROVENANCE_REUSE_ENABLED=false` and apply configuration through the normal Cloud deployment. Keep automation false and all NFL/source settings unchanged. If code rollback is needed, release `2dbf544c` tolerates the additive unused table. Retain provenance, reviews and accounting; do not run migration down in production.

## Verification record

See [the dated verification record](nba-review-reuse-verification-2026-11-21.md) for exact checks and production readback. The rollout remains blocked on reviewed deployment/activation and a bounded production reuse canary; local fake-provider tests do not constitute a paid production canary.
