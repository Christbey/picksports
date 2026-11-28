# Calculation recovery and publication ownership

Claims and result/failure persistence lock canonical event, run and latest attempt in that order. Calculators run outside these SQL locks. Each attempt has a unique owner token and bounded lease; only the current running, unexpired owner can persist. Expired work may be abandoned and reclaimed; late prior owners cannot replace a newer result. Successful matching runs remain reusable.

Publication uses event-first current locking reads. Pregame admission rechecks the current event start; a historical effective date is not permission to generate post-start pregame evidence. Recovery limits and cooldowns come from prediction_lifecycle configuration. Preserve additive audit history and coordinate compatible workers; leases do not certify calculator duration or external delivery.
