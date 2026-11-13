# Automatic cookie document assessment implementation plan

Goal: automatically inspect linked cookie documentation and return specific evidence and missing-information results, with no instruction to verify manually.
Architecture: bounded source crawl retains cookie document blocks and their URLs; one combined assessment links every positive finding to its source. Deterministic context-aware rules distinguish missing content from unmeasured runtime behavior.
Spec: existing DESIGN.md plus user's explicit request to automate policy checks. Keep cookie-only scope.

- [x] Add regression fixtures for paraphrased withdrawal instructions, negative statements, cookie-specific durations versus unrelated retention, table inventory, linked-document aggregation and queue prioritization/caps.
- [x] Implement document block extraction and per-check outcome, evidence, source URL and actionable recommendation in core.php / policy.php. Actual consent interaction stays unmeasured while sandbox is blocked.
- [x] Prioritize newly discovered cookie documents within existing five-page cap; aggregate documents at completion and re-evaluate against all observed source cookies/services. Never turn an unloaded document into a passed check.
- [x] Render precise automated findings consistently in UI, charts and PDF; remove blanket manual-verification instructions without inventing confidence. Test full project, review, deploy and exercise the public UI/PDF.
- [x] Persist source and verified limitations in existing Git branch.

Review focus: generic GDPR retention mistaken for cookie lifetime; negated consent instructions; missing versus inaccessible text; cross-document evidence provenance; crawl caps and token redaction.


Completed: deployed1.4.1, 95 PHP assertions +15 Node tests passing; independent review findings
reproduced and fixed. Live UI scan/PDF verified. Full browser automation remains blocked by hosting
sandbox; no security downgrade attempted. User receives automated scoped document findings, not a
claim that every website behavior or legal obligation can be established by source inspection.
