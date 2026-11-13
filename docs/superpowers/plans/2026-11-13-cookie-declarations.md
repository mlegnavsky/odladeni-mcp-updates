# Cookie declaration discovery and category assessment

Goal: discover the same public CookieYes data the user sees, show all declared entries and category percentages, and correct false missing-document findings.
Scope: declarations are public documentation, never evidence of actual storage/consent behavior. Existing hosting blocks sandbox; no bypass.

- [x] Test safe account discovery, literal-only category parsing, language/audit JSON resolution and resource limits; no JavaScript execution.
- [x] Load at most two CookieYes accounts per scan through existing DNS-pinned public transport. Preserve exact sources; retain no cookie values. Display failures rather than false missing data.
- [x] Include declared table in policy evidence only where that account's audit-table mount exists. Extract names/lifetimes/descriptions/categories/domains and original source URLs. List declarations separately from observed HTTP cookies.
- [x] Shared category assessment: equal-weight name/domain/lifetime/purpose/classification fields; missing/placeholder descriptions fail, unknown category fails classification. Sum passed/total fields into an overall declaration percentage, not an average of unequal categories. Zero-entry category is not scored. Show detailed rows, colored category cards and PDF.
- [x] Display actual browser runtime reason from server; do not imply that declaration retrieval performs consent scenarios, contrast measurement or actual JavaScript storage measurement.
- [x] Review/tests, version/deploy, live UI/PDF and exact21-entry regression verification, persist source.

Review focus: executing sourceJS, SSRF paths/redirects, declaration -> runtime proof, language mismatch, unsupported script shape -> false absence, failed dynamic source -> complete score.

Completed declaration scope in 1.6.3. Full consent/contrast/browser scanner remains unfinished; runtime probe could not verify content sandbox. No sandbox bypass. See VERIFICATION.md.
