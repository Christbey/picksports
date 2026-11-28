# Billing role provenance and effective access

New provenance admission defaults OFF (`BILLING_ENTITLEMENT_PROVENANCE_ENABLED=false`). Install and verify110000 schema across the fleet, current-model/permission connections, cache repair and engine controls before enabling it. This candidate has not been deployed or enabled by the readiness work. Recorded derived grants remain checked against current canonical subscription instants even if new admission is later disabled; disabling the flag does not turn them into manual grants.

A stored role name is not proof of a manual or subscription-derived grant. Existing unclassified tier roles preserve their existing access interpretation but block canonical activation with `entitlement_role_origin_unknown` until an operator explicitly decides intent. The canonical projection rolls back and remains in attention; no automatic retry/provider call resolves that ambiguity. Use the separately audited reconciliation operator path only after classifying every relevant grant. Never mass-classify old roles from their names.

Admin **Assign Tier** is an explicit manual override. It replaces competing *known* manual/derived/system_default tier grants and records their transitions. A competing unclassified tier refuses rather than being silently revoked. Unrelated/direct/admin/founding permissions remain independent. Reconciliation cannot undo an explicit manual downgrade. Billing status and effective tier are separate facts: a charged subscription can remain active while an administrator has explicitly overridden effective access. Charge-bearing admissions must refuse that ambiguity before provider work.

To remove an explicit override, inspect the exact scope first:

```sh
php artisan billing:clear-manual-tier-override --user=123 --reason='Approved removal of the explicit manual override'
```

Add `--apply` only after reviewing that scope. The command accepts1–100 exact unique existing User IDs and a required bounded single-line reason. Every requested User must have an unambiguous explicit manual override before any application. It reports requested/applied/changed/failed counts, makes no provider/queue request and does not automatically reconcile billing. Revoked provenance is retained; later reclassification is explicit. A separately admitted reconciliation may subsequently refresh derived role projection.

Read-side authorization does not mutate role membership or audit. Raw role labels describe membership, not current entitlement. Effective permissions and tier readers apply the recorded provenance and exact canonical expiry. Audit receipts retain actual observed transitions; old manual history and original grant timestamps are not reconstructed. Ordinary/quiet/numeric/delete instance mutations of receipts are refused; privileged SQL remains a separate operational trust boundary. Candidate rollback refuses to destroy provenance.

The isolated nineteen-additive checkpoint passed the two current-state MySQL controls and schema upgrade; see the exact frozen proof (private verification record retained separately). Fleet/cache/client acceptance and a current integrated release check remain gates. This runbook is not evidence that the candidate is active in production.

Durable billing and provenance admission must be admitted together for unrecorded accounts: durable mode ON with provenance OFF refuses canonical paid admission with `entitlement_provenance_required` before a financial send. Recorded users continue typed reconciliation with admission OFF. Explicit operator resume is separate from fixing configuration or classifying grants; use the billing operator's category-specific preflight and audited resume options.

Future signup/OAuth accounts created with provenance admission ON record their exact default grant as `system_default`. This is a replaceable default, not a manual override; existing unclassified default roles remain unknown. Local User creation and this grant are atomic. Manual grant/clear refuses while any unresolved charge-bearing checkout/swap/legacy_swap intent remains active, regardless of lease expiry. Cancel/resume/portal management does not by itself create that charge hold.
