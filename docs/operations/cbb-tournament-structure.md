# CBB tournament structure repair

The private candidate repairs missing NCAA round-of-64 placeholders using the existing real-game priority, First Four winner/unresolved participant, season fallback, seed pairing and date defaults. It preserves an updated placeholder’s primary key and existing event link. It does not create events, publish forecasts, change approval, or rewrite retained history.

The repair uses one existing game/team connection transaction. Every intended save and deletion must be acknowledged and match current primary rows; all at most 32 planned slot headers are checked again after the final observer. Cancellation, requested-value or identity drift, missing rows, and linked/history-bearing obsolete placeholders cause refusal and rollback. Database foreign-key refusal is retained.

Evidence: corrected portable compatibility 13 tests / 81 assertions at 256 MiB; the same 12 boundary Feature cases passed MySQL 8.4.6 with 72 assertions at 128 MiB. The disposable fixture retained 382 migrations / 269 tables, stopped cleanly, and used no grant or instrumentation changes. Prior original-source failures and the separately demonstrated draft primary-key gap remain preserved. See private adoption (private verification record retained separately).

Initial game and team collection reads remain unbounded. The 32-slot final check is not a collection memory bound or global/concurrent allocator proof. This private change is later than frozen Full9 and does not certify deployment, production state, all historical references, or hosted CI.
