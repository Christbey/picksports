# Durable subscription webhook recovery

This implementation is a disabled candidate. `BILLING_DURABLE_WEBHOOKS_ENABLED=false` remains the default. The existing webhook path remains vulnerable to event ordering until this mode is activated through the acceptance gates below. Source changes and focused tests do not certify the entire application or authorize production activation.

## Supported boundary

The durable path covers `customer.subscription.created`, `.updated`, `.deleted`, and `customer.deleted`. Mandatory Stripe signature verification remains in place. Original signed bytes and canonical JSON depth are bounded before admission; PHP/web-server/proxy buffering still needs infrastructure limits. Full raw events, API keys, headers, payment amounts and payment instruments are not persisted in the new ledger.

A signed event admits an immutable account/mode/event identity, canonical payload hash and pending customer generation. It does not apply the mutable event subscription body. Identical retries create no new work. A different payload for an admitted event ID commits conflict evidence, revokes the old generation and holds that customer in attention. Automatic scans and operator retry-exhaustion resets cannot clear this integrity hold.

The reconciler claims a customer first, commits the SQL claim, fetches bounded authoritative resources outside SQL, and applies current subscription/items/derived roles with its receipt and before/after audit in one local transaction. Newer generations, stale leases, wrong owners, customer linkage changes and post-callback expiration prevent commit. All participating models must use the checked default connection, and admission/recovery refuses inherited transactions. Unknown customers remain pending linkage; ambiguous exact bindings require attention. Opaque Stripe IDs are case sensitive, including legacy-column lookup checks.

Other vendor webhook handlers remain outside this reviewed durable scope. Unmarked accounts retain their characterized legacy ordering behavior when durable admission is disabled. Existing canonical subscriptions, recorded entitlements and immutable history remain protected regardless of issuance flags; disabling a flag does not authorize an old writer to overwrite marked rows. Supported legacy webhook handlers refuse canonical rows before period lookup/mutation, and customer deletion has a current User-locked atomic boundary.

## Provider and execution limits

Configure an explicit platform account ID and explicit boolean mode using `BILLING_STRIPE_ACCOUNT_ID` and `BILLING_STRIPE_LIVEMODE`. Missing mode is not inferred from a key prefix. The effective Cashier key/version is used, without changing global SDK HTTP clients or retries. The only permitted API origin is `https://api.stripe.com`; Connect overrides and custom origins are refused.

The reconciliation adapter performs GET requests to fixed account/event/customer/subscription/item paths, plus a bounded invoice lookup when a recorded application financial operation requires invoice proof. The shared transport validates and pins public DNS answers, forbids redirects/proxies and enforces body limits. Default limits are four subscription targets, 100 aggregate items, three item pages, eight provider calls and a 60-second aggregate fetch budget. Over-cap/incomplete inventory is refused, never silently projected as a complete list. Generic404, auth errors, rate limits, malformed responses and provider failure preserve the last known projection. Retries use bounded backoff; default exhaustion is eight claims, then attention.

A private PHP child supplies a hard parent timeout, bounded stdout/stderr and a required self-alarm, including blocking native DNS. It executes reviewed read-only provider code, not arbitrary user code. SQL is denied before execution on existing/new connections after reviewed bootstrap; provider bootstrap is not a sandbox. Actual production CLI/pcntl, credentials, HTTP transport, process termination and worker compatibility still require acceptance. Focused author tests use fakes and do not execute that provider child or call Stripe.

Confirmed deletion requires a fetched exact platform account, the same admitted deletion event fetched with matching mode/type/customer/account evidence, and a CURRENT HTTP 200 exact customer tombstone. A missing resource or an old deletion event whose customer is currently live does not clear entitlement. Events outside provider retention can therefore require manual attention.

## Queue and scanner

HTTP handoff is skipped for synchronous queues before any claim, so no provider fetch runs in the webhook request. Reviewed asynchronous database/Redis handoff uses the existing configured default queue. The default billing job timeout is 90 seconds (60-second fetch plus 30-second local margin), below the documented default worker 120-second timeout and backend visibility. Incompatible budgets/worker contracts/visibility are refused before claim; deployment process flags remain the actual authority.

Queue handoff first creates a durable SQL lease, then enqueues its attempt ID and owner token. Ambiguous enqueue failure cannot erase a receipt or immediately create another owner. A lost handoff expires and is reclaimed. Exactly one duplicate delivery can mark execution start; that start grants one fresh bounded execution lease so queue wait does not consume the fetch budget. Further starts cannot renew it. A crashed started attempt must expire before recovery.

`billing:scan-reconciliation --limit=10` previews existing due/expired work. `--apply` claims/enqueues it and repairs durable permission-cache invalidation flags; the scanner itself never reads Stripe. A feature-gated minute schedule applies a bounded pass. Database leases are the safety boundary; the scheduler mutex is an optimization. Failed cache repair retains its flag, including an adapter returning false while retaining the key. Verified absence may clear the exact committed generation/lease marker; a newer marker is preserved. Cache repair uses the existing permission cache contract, not an all-process cache-coherence guarantee.

## Explicit operator recovery

Use positive **local primary keys**, not provider IDs:

```bash
php artisan billing:reconcile --user=123
php artisan billing:reconcile --subscription=456
php artisan billing:reconcile --state=789
php artisan billing:reconcile --subscription=456 --reason='Reviewed current subscription after provider recovery' --apply
```

Preview is the default and creates no state, receipt or intent. Select at most 100 explicit IDs in total. User/state selection includes its bounded local subscription inventory; explicit subscription selection can be narrower, but cannot discard outstanding receipt or operator targets. Every selected scope/context/binding is preflighted before the first admission. Apply rechecks each customer under current locks. A later concurrent change can stop the batch after earlier per-customer admissions; the command explicitly reports `partial` and the admitted state/intent IDs. It is not an all-customer transaction.

An operator request is an immutable `operator_request`/`requested` history row with local request time, bounded targets/reason and prior retry state. It is not a fabricated Stripe event or provider timestamp. `requested` means intent admission succeeded, not that provider reconciliation completed. Live leases and integrity holds are refused. `--reset-exhausted` explicitly audits retry exhaustion reset; it does not clear hash-conflict attention. Avoid sensitive material in audit reasons.

The state references the current immutable intent/target generation. Later ordinary Stripe generations can cover an older still-current intent; old work cannot clear a newer intent. Successful complete projection clears only the captured matching pointer. Historical requests remain immutable, including superseded intent references. A restrictive foreign key preserves referenced history; raw SQL remains an explicit administrative boundary for cross-row intent tuple integrity.

## Timestamp and activation gates

The **new recovery and mutation tables** store and cast operational/evidence SQL dates as UTC DATETIME(6), with no database-generated timestamp defaults, including leases, next-attempt time and automatic timestamps. Original normalized provider epochs are retained in bounded whitelisted attempt evidence on success and safe projection failure.

Migration090000 adds canonical provider epoch sidecars and an observation marker to existing subscriptions. The custom Cashier subscription model uses marked epochs for date accessors, instance validity predicates, trial/grace predicates and SQL scopes. Both repeated-hour instants remain distinct. Canonical trial equality is classified as expired; a continuing active subscription does not lose paid access merely because its trial ended. Legacy rows retain their historical application-timezone semantics. Mirrored legacy SQL dates are compatibility values, not the authority for marked rows. The application timezone and old rows are not reinterpreted.

The040000 recovery tables and100000 mutation tables use explicit UTC DATETIME(6) storage/casts/bindings without database-generated date defaults. Their operational lease times are distinct from legacy Cashier dates. The090000 marker migration and immutable history migrations refuse destructive rollback. Old authenticating/billing workers must be drained before enabling canonical issuance; rollback requires retaining compatible readers/adapters and history, not dropping markers to expose naive dates to old code.

Before activation, require:

- Current migration/engine upgrade and concurrency tests, rollout backup/restore evidence and a forward-only history retention plan. The additive migration refuses destructive rollback.
- Verified platform account, explicit test/live mode, effective Cashier API context, required GET access and deletion-retention policy. No live provider verification was performed in this implementation task.
- Both durable event admission and entitlement provenance admission configured for new canonical users; recorded entitlements continue their typed protection when an issuance flag is off. New charge admission refuses missing provenance. Unknown existing tier pivots require explicit classification; enabling a flag does not invent their origin.
- A coordinated webhook/worker drain and deployment. Mixed legacy body writers and durable projection writers are not a safe deployment strategy.
- Actual asynchronous worker/default-queue/timeout/visibility and CLI child/runtime acceptance, including kill/recovery behavior; a functioning scheduler and attention alerts.
- Review of bounded customer/subscription inventory, unknown/ambiguous linkage, explicit grant provenance/default enrollment, conflict/manual recovery policy, provider failure/exhaustion, cache repair and every coordinated local/external writer.
- Current focused/engine and application verification after the final frozen source revision. Earlier checkpoints are dated evidence, not current whole-application certification.


## Financial mutations and account ownership

Authenticated financial requests admit immutable100000 operation intents under the current User lock before any provider I/O. Equivalent active intents are reused server-side; contradictory or unresolved operations are refused. New checkout/swap charges require configured canonical entitlement admission and classified current tier roles. Any known administrator access override blocks a new charge, including a matching override, until an explicit purchase policy or audited override removal resolves it. Cancellation and payment management remain separate from effective access. Manual grant/removal paths serialize with unresolved charge intents and do not clear them by lease expiry.

`billing:scan-mutations --limit=10` previews bounded pending/expired financial work. `--apply` uses only the reviewed asynchronous default queue and never reads the provider in the scanner. Its minute schedule is gated off with durable admission. Queue handoff has a once-only current start token; a delayed valid start establishes a fresh90-second execution lease. Duplicate or expired/replaced starts are refused. Each fixed-purpose private child has a30-second parent deadline, so one prepare plus one send fits the90-second job/120-second worker contract with local commit margin. Actual process/worker flags and failure handling require deployment acceptance.

The financial child performs only enumerated preparation/read/POST operations at the exact Stripe API origin. It proves account and mode before a send; a brand-new customer requires exact target-price mode evidence because account lookup alone has no mode. Preparation is GET-only. Before the single POST, it rechecks provider preconditions and the exact prepared request hash. An immutable possible-send receipt and unique step key commit before that call. Provider mutations never run under SQL locks. Unknown response, worker death or a late acknowledgement retains a durable uncertainty hold; no scanner or lease expiry blindly repeats the mutation.

The characterized plan-change profile is a single licensed recurring item, existing quantity, installed Cashier `default_incomplete` payment behavior, `always_invoice` proration, and preserved future trial or `trial_end=now`. Unsupported metered/multiple-item/tax profiles require attention rather than silently changing financial policy. Matching price alone is not proof of invoice/payment outcome. A current operation/subscription/invoice/customer/account/mode proof confirms a paid outcome; an unconfirmed swap preserves the prior local projection. A continuing explicitly observed trial can complete a plan change without representing it as a paid charge. Competing external provider administration cannot be made transactional by local SQL; supported provider configuration, competing writer retirement and operational controls must be verified before activation.

Customer creation and checkout are separate journaled steps. A known session is read with its exact owned operation/customer/mode binding. Current complete paid/no-payment-required sessions can admit an honest observed subscription target even when the create webhook was missed. A current expired session without paid outcome or attached subscription can release that session hold; customer linkage is retained. Missing/ambiguous customer or session creation remains held for support recovery. Legacy default-off SDK checkout also records admission before hidden customer creation and propagates the generated operation UUID. A signed exact matching projected legacy subscription can record `legacy_subscription_linked` and restore normal management; that evidence is not canonical ordering, mode or payment certification.

Owner-only `/subscription/operations/{intent}` responses are private/no-store and disclose no other user's operation. Mutable continuation results are encrypted and expire locally after15 minutes. Only validated provider checkout/portal/hosted-invoice URLs and owned identifiers are stored; no client secret or raw financial payload enters the journal/result. Binding changes suppress a URL separately from actual TTL expiry. The UI fences delayed responses across operation replacement/unmount and does not imply payment/access completion from admission.

New payment continuation uses a validated provider-hosted invoice URL with exact acknowledged operation→subscription→invoice/customer/account/mode binding. Missing support stays pending/attention. The vendor's former public opaque-payment-ID audience is retired: authentication plus immutable local ownership evidence is required before any provider lookup. Old links without that evidence need recovery; there is no public payment-intent-ID fallback.

The billing portal requires `BILLING_STRIPE_PORTAL_CONFIGURATION_ID` to identify an explicitly configured active account/mode-bound configuration whose subscription updates are disabled. The application neither creates nor changes configurations. All newly issued sessions use that exact configuration and a configured HTTPS application return URL, independent of incoming Host headers. An unconfigured/rejected portal gives actionable guidance; local cancellation remains available. Verify the actual provider configuration and retire existing unrestricted portal sessions/old payment links before activation. A local result TTL does not revoke a provider URL.

Profile deletion takes the same current User lock as checkout admission and refuses retained customer/subscription/reconciliation linkage or unresolved financial creation before logout/delete. Locally canceled status is not provider-termination proof. Free accounts without billing still delete. Rejection preserves the session and explains support/management recovery. Complete customer inventory and proven provider termination are required for later billing-account deletion; this pass performs no provider customer deletion or bulk historical cleanup.

## Operator classification recovery and admin refresh

`--resume-classified-role-origin` can resume only `entitlement_role_origin_unknown` after whole-scope preflight and current locked classification. `--resume-entitlement-admission` can resume only `entitlement_provenance_required` after current configured/recorded admission validation. Both preserve immutable audit of the prior category and explicit reason. A manual grant or flag change alone does not reset attention, and neither option clears integrity/hash-conflict holds.

Admin single-customer refresh now admits a durable operator intent. Batch refresh selects a bounded ascending local-user cursor (default20, maximum100), preflights the entire selected scope, and reports its next cursor or explicit per-customer partial admission. Neither HTTP action loops through provider calls or writes vendor-base subscription instances. The scheduler/worker performs recovery. Billing screens distinguish the stored billing plan, effective access tier, current provider-observed period end and separately scheduled access end; unavailable renewal information is not invented.

Current exact-source SQLite/native/frontend results are recorded in the dated implementation progress report and coverage ledger. These controls do not replace a current whole-application release run, verified process/runtime/provider configuration, source-bound upgrade/restore evidence, fleet drain or explicit activation approval.
