# CFB adaptive calibration storage

Dated private-candidate qualification: November 28, 2026.

The private storage change keeps replacement creation and deactivation of the previously active season profile in one model-connection transaction. A cancelled creation is rejected rather than acknowledged as a stored profile. An ordinary creation exception or cancellation rolls back the previous profile's deactivation. Valid active replacements and inactive candidates retain their existing values and behavior.

The clean unmodified SQLite controls produced two genuine failures and two positives (31 assertions). The patched same four controls plus the unchanged public-command compatibility case passed five tests and 44 assertions. Both positive raw profile row arrays were conserved exactly; both failure cases retained the complete original row after rollback. The first fixture epoch had teardown errors from a forward-only migration and remains preserved separately.

The public compatibility case performs bounded synthetic PHP calibration learning. These results do not establish external model training, empirical scientific approval, native database behavior, concurrent season-activation uniqueness, arbitrary observer resistance, production activation, or a full candidate regression pass. Learning formulas, defaults, activation policy, and existing historical profiles are unchanged. The formatted new test and command passed Pint in the separately recorded current-byte epoch. This change was accepted into the private candidate as repair 110 under its recorded hash-bound Root permit. It has not been deployed to production.
