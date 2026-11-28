# Subscription handler transaction boundary

Supported known-customer subscription handlers serialize local projection and tier-role writes on the customer connection. Supported entry requires transaction level zero; local state is rechecked with current locking reads. Period resolution is prepared before the owned handler transaction. A failed local write must preserve the previous projection.

This bounded transaction is not a durable event inbox or provider-order convergence system. Unknown-customer recovery, same-second conflicting snapshots, stale provider retrieval and external cache effects require their own policies. Never treat event-created time or arrival order as complete provider revision order. No live provider operation is authorized by this document.
