# Developer platform availability boundaries

Local batch and webhook services are foundations, not a supported external delivery product. Preparing a batch persists local data and does not by itself export it. An unbound controller or missing delivery worker remains unavailable rather than implied by an implementation class.

Before introducing a sender, require reviewed destination admission, public pinned destinations, bounded payload/response/attempt budgets, tenant equality, current endpoint status, immutable event bodies and fenced terminal transitions. HTTPS syntax alone is not an SSRF-safe transport. Do not expose signing secrets or activate delivery as a documentation step.
