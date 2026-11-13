# Persistent measurements and missing-cookie-document diagnosis

Goal: every started measurement survives reload/cache expiry; missing documentation produces a concrete bounded finding rather than an unexplained dash.
Architecture: private WP database table keyed by random report ID and hashed capability token. Full job snapshots save at start and each step; transients become cache only. Public POST report retrieval authenticates the existing token. Browser history stores only ID/token/URL/date locally, with private fragment links. WP administrator sees paginated archive and can open/download reports. No public listing, IP retention or cookie values. Existing rate/SSRF bounds remain.
User explicitly requests implementation and has opted out of approval questions.

- [x] Reproduce spd.cz placeholder link; test broken named document links and successful crawl/no policy vs inaccessible policy.
- [x] Add policy_discovery summary (missing/found/unavailable/pending). Missing in fully fetched bounded scope scores absent documentation checks zero; fetch/access failure suppresses percentage. Preserve source evidence and no global/legal absence claim.
- [x] Add DB archive schema and fail-closed durable save/read; require persistence before returning success. Test after transient eviction, expired access metadata, wrong token, running/completed/failed distinct snapshots and save failure.
- [x] Add resume/open REST endpoint, local visitor history/private links, admin paginated archive with report viewer/PDF. Display archive ID and permanent-storage copy, no token in query/referrer.
- [x] Full tests/lint, independent review, deploy gdpr-cookies.cz only. Live spd.cz scan, reload/reopen/PDF and admin evidence where accessible. Persist source + limitations in Git.

Review focus: authorization leaks, silent DB failures, token/nonce cache, missing -> fake pass, unknown -> fake zero, history opening starts new measurement, per-domain test cap.

Completed 1.7.2. Legacy import is insert-only (no cache writes) to preserve concurrent newer snapshots. Review cases reproduced RED then GREEN. Live UI/PDF and two-domain history verified in1.7.1;1.7.2 adds legacy import only. Server archive schema and import status verified separately. Browser consent/contrast remains unfinished.
