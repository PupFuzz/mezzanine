# Design-doc verifiers (review-time guards)

- `verify-event-schema.py` — structural: every JSON block parses, every internal anchor resolves, no review-finding ids or TODO/TBD markers leak into the doc.
- `verify-harness-facts.py` — harness-fact: re-derives hook payload key sets from the INSTALLED Claude Code binary on every run (no stored ground truth) and checks the doc's § 17 fixtures against them. Version-dependent by design: run it on the harness version the doc pins in § 6.0, and re-run after any harness upgrade.

Both were seen to fail on planted defects before being trusted (D1 review rounds 1–3). Run both before approving any change to docs/design/EVENT-SCHEMA.md. AT-21 in the doc is the separate BUILD-time obligation; these are the review-time guards.
