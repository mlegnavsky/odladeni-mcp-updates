# Cookie documentation percentage and detailed assessment

Goal: give the user a prominent percentage and concrete missing-information findings for the publicly inspected cookie documentation.
Spec: user's explicit request for percentage, detailed analysis and no blanket manual-verification messages.

- [x] Implement/test a shared documentation score: six document checks plus each observed service and cookie, equally weighted; pass/total percent rounded to integer. Preserve missing/conflicting failures. Inaccessible/incomplete inputs suppress the percentage. Browser outcomes cannot change or masquerade as documentation coverage.
- [x] Add prominent percent, passed/total counts, transparent formula and per-criterion evidence/recommendation table in the dashboard and PDF. Label the scope as documentation, not full website/legal compliance. Use 'Chybí v dostupné dokumentaci' for missing information.
- [x] Review/test, version/deploy to gdpr-cookies.cz only, verify live UI and exported PDF, persist source and limitations in Git.

Review focus: unknown -> artificial 0/100; failed policy fetch; duplicate criteria; claiming global compliance; score mismatch between UI and PDF.


Completed:1.5.2 deployed.96PHP assertions/20Node tests pass; independent findings reproduced/fixed.
LiveUI/PDF38%=3/8 verified. 100% reserved for full completion; any unavailable/truncated document
prevents completion. Percentage only describes inspected documentation, never unmeasured browser behavior.
